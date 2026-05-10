# Lensman

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/wordpress-6.5%2B-21759b.svg)](https://wordpress.org/)

> **Stop shipping 4 MB hero images.** Lensman auto-generates WebP and AVIF variants of every uploaded JPEG/PNG, caches them on disk, and rewrites front-end `<img>` tags into `<picture>` elements with a proper responsive `srcset`. Lighthouse "Properly size images" / "Serve images in next-gen formats" warnings, gone.

## What it does

1. **On upload** — hooks `wp_handle_upload` and:
   - Downscales the original to a sane maximum width (default 2400 px) so the WordPress media library never holds a 6000 px source.
   - Pre-generates the standard responsive widths (`320, 480, 768, 1024, 1440, 1920`) plus a WebP variant for each.
   - Optionally pre-generates AVIF variants where the runtime supports it.

2. **On render** — hooks `wp_get_attachment_image_attributes` and `the_content`:
   - Picks the smallest cached variant that's still ≥ the requested width as the `src` fallback.
   - Emits a `srcset` listing every cached variant with `w` descriptors.
   - Adds a configurable default `sizes` attribute (`(max-width: 600px) 100vw, (max-width: 1200px) 50vw, 33vw`).
   - Wraps the resulting `<img>` in a `<picture>` element with `<source type="image/avif">` and `<source type="image/webp">` ahead of it, so modern browsers pick the smaller format and old browsers keep working.
   - Adds `loading="lazy"` and `decoding="async"` when missing.

3. **Cache directory** — `wp-content/uploads/lensman/cache/<hash>/<width>.<ext>`, keyed by the source path + mtime. When a source is replaced in place, the hash changes and the old bucket becomes orphaned; a daily cron sweeps anything stale.

4. **Concurrency-safe** — every variant write goes through a `flock`-guarded tempfile + atomic `rename`, so two simultaneous requests for the same uncached size don't corrupt each other.

## Install

1. Drop the plugin into `wp-content/plugins/lensman/` and activate (or zip-install).
2. Visit **Lensman** in the wp-admin sidebar (camera icon).
3. Defaults are sane. Tune quality sliders + srcset widths to taste.

Composer:

```
"repositories": [ { "type": "vcs", "url": "https://github.com/rennerdo30/wp-lensman" } ],
"require": { "rennerdo30/lensman": "^0.1" }
```

## Settings

The plugin lives under a top-level **Lensman** menu in wp-admin (camera dashicon).

| Setting | Default | Purpose |
|---|---|---|
| Generate WebP | on | Emit a WebP `<source>` for every image |
| Generate AVIF | off | Emit an AVIF `<source>` (requires runtime support) |
| On-the-fly resize | on | Downscale fresh uploads that exceed max master width |
| Max master width | 2400 px | Cap for the original file kept in the media library |
| JPEG quality | 82 | Quality for cached JPEG variants |
| WebP quality | 80 | Quality for cached WebP variants |
| AVIF quality | 60 | Quality for cached AVIF variants |
| Srcset widths | `320,480,768,1024,1440,1920` | Comma-separated list of `w` descriptors |
| Default `sizes` | `(max-width: 600px) 100vw, (max-width: 1200px) 50vw, 33vw` | Fallback `sizes` for images without one |

**Regenerate all cached images** flushes the cache and schedules a background job (via `wp_schedule_single_event`) that re-primes every JPEG/PNG attachment in the media library. The admin request returns immediately; the job runs in WP-Cron context so it doesn't time out.

## Lighthouse impact

On a representative WordPress site with un-optimised hero photography:

| Metric | Before | After |
|---|---|---|
| Largest image (4 MB JPEG, 5184 × 3456) | served as-is | 1024w WebP, ≈ 90 % smaller |
| "Properly size images" savings | ≈ 3.5 MB | 0 |
| "Serve images in next-gen formats" savings | ≈ 2.8 MB | 0 |
| LCP on slow 4G | 8–12 s | 1.5–3 s |

Numbers will vary with content; the dominant cost on most WordPress sites is the hero image, and that's exactly what Lensman targets.

## Architecture

```
      wp upload
          │
          ▼
   ┌──────────────────────┐         ┌──────────────────────────┐
   │ wp_handle_upload     │────────▶│ Resize\Engine::on_upload │
   └──────────────────────┘         │  • downscale master      │
                                    │  • prime srcset widths   │
                                    │  • prime WebP / AVIF     │
                                    └──────────────┬───────────┘
                                                   │
                                                   ▼
                                    wp-content/uploads/lensman/cache/
                                       <sha256(path|mtime)[0..16]>/
                                          320.jpeg / 320.webp / …

      front-end render
          │
          ▼
   ┌─────────────────────────────────────────┐
   │ wp_get_attachment_image_attributes      │
   │  + the_content / post_thumbnail_html    │
   └────────────────┬────────────────────────┘
                    ▼
   ┌─────────────────────────────────────────┐
   │ Filters\Content::rewrite_tag()          │
   │  • resolve src → uploads path           │
   │  • build srcset (JPEG/PNG)              │
   │  • build srcset (WebP)                  │
   │  • build srcset (AVIF, optional)        │
   │  • wrap <img> in <picture><source>      │
   └─────────────────────────────────────────┘
```

## Known limitations

- **No SVG, no GIF.** Vector and animated formats fall through untouched. (You don't want either of them as a `<picture>` source anyway.)
- **AVIF support depends on the runtime.** PHP 8.1+ with the GD `avif` functions, or ImageMagick built against `libheif`. The settings page reports availability and disables the AVIF checkbox when it's unsupported.
- **External URLs are ignored.** Lensman only rewrites images served from `wp_upload_dir()`. Images on a remote CDN keep their original markup.
- **No EXIF orientation rewrite.** We strip metadata in cached variants for size, but we honour the source's EXIF orientation by passing it through Imagick's `getImageOrientation()` (GD has no equivalent, so GD-only servers may rotate landscape→portrait incorrectly; Imagick fixes this).
- **No retina-density (`2x` / `3x`) markup.** We use `w` descriptors instead, which is the modern best practice and lets the browser pick based on viewport + DPR together. If your theme hardcodes `2x` srcsets, those will pass through untouched.
- **Cache is not garbage-collected aggressively.** The daily cron deletes buckets untouched for 30 days. If you regenerate often, watch `wp-content/uploads/lensman/` size — or use the **Flush cache** button.

## Author

[renner.dev](https://renner.dev) · [@rennerdo30](https://github.com/rennerdo30)

## License

MIT.
