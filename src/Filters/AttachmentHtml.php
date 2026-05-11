<?php

declare(strict_types=1);

namespace Lensman\Filters;

use Lensman\Plugin;
use Lensman\Resize\Engine;

/**
 * Wraps wp_get_attachment_image() output in a `<picture>` block with
 * WebP / AVIF sources.
 *
 * Filters\AttachmentImage already injects the Lensman srcset into the
 * <img> attributes via wp_get_attachment_image_attributes, but that
 * filter cannot return HTML — only attributes — so it cannot emit a
 * <picture> wrap or next-gen <source> tags. This filter, registered on
 * wp_get_attachment_image (full HTML), closes that gap.
 *
 * Why it matters: themes (including the EUPD hero carousel) call
 * wp_get_attachment_image() directly. Without this filter, every such
 * caller misses the WebP/AVIF wins entirely.
 *
 * Also captures `fetchpriority="high"` images for an LCP preload hint
 * emitted from wp_head, see Plugin::register_preload_emitter().
 */
final class AttachmentHtml
{
    private Engine $engine;
    /** @var array<string,mixed> */
    private array $settings;
    private bool $cache_writable;

    /** @var array<int,array{srcset:string,sizes:string,type:string}> */
    private static array $preloads = [];

    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(Engine $engine, array $settings, bool $cache_writable)
    {
        $this->engine         = $engine;
        $this->settings       = $settings;
        $this->cache_writable = $cache_writable;
    }

    public function register(): void
    {
        if (!$this->cache_writable) {
            // Cache is unwritable; emitting <picture> would point at
            // 404ing variant URLs. Bail before wiring the filter.
            return;
        }
        // Priority 99 so we wrap AFTER Filters\AttachmentImage has
        // rewritten the underlying <img> srcset (priority 99 on
        // wp_get_attachment_image_attributes runs before this point).
        add_filter('wp_get_attachment_image', [$this, 'filter_html'], 99, 5);
    }

    /**
     * @param mixed $html
     * @param int $attachment_id
     * @param string|int[]|array{0:int,1:int} $size
     * @param bool $icon
     * @param array<string,mixed> $attr
     * @return string
     */
    public function filter_html($html, $attachment_id, $size, $icon, $attr): string
    {
        if (!is_string($html) || $html === '') {
            return is_string($html) ? $html : '';
        }
        if (is_admin() || is_feed()) {
            return $html;
        }
        // Defensive — never double-wrap.
        if (stripos($html, '<picture') !== false) {
            return $html;
        }
        // Find the <img …> in the rendered HTML.
        if (!preg_match('/<img\b[^>]*>/i', $html, $im)) {
            return $html;
        }
        $img_tag = $im[0];

        $mime = get_post_mime_type((int) $attachment_id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $html;
        }
        $path = get_attached_file((int) $attachment_id);
        if (!is_string($path) || !is_file($path)) {
            return $html;
        }

        $target = $this->target_width_for_size($size, $path);
        $desc = $this->engine->descriptor($path, $target);
        if (!$desc) {
            return $html;
        }

        // Capture an LCP preload candidate when the theme flagged this
        // image as high-priority (the hero LCP slide).
        $fetchpriority = '';
        if (is_array($attr) && isset($attr['fetchpriority'])) {
            $fetchpriority = (string) $attr['fetchpriority'];
        }
        if ($fetchpriority === '' && preg_match('/\bfetchpriority=("|\')([^"\']+)\1/i', $img_tag, $fm)) {
            $fetchpriority = $fm[2];
        }
        if (strtolower($fetchpriority) === 'high') {
            self::register_preload(
                (int) $attachment_id,
                (string) ($desc['webp_srcset'] ?? $desc['srcset']),
                (string) $desc['sizes'],
                !empty($desc['webp_srcset']) ? 'image/webp' : ($mime ?: 'image/jpeg')
            );
        }

        return $this->engine->build_picture_html($img_tag, $desc);
    }

    /**
     * @param string|int[]|array{0:int,1:int} $size
     */
    private function target_width_for_size($size, string $path): int
    {
        if (is_array($size) && isset($size[0])) {
            return (int) $size[0];
        }
        if (is_string($size)) {
            $registered = wp_get_registered_image_subsizes();
            if (isset($registered[$size]['width'])) {
                return (int) $registered[$size]['width'];
            }
            if ($size === 'full') {
                [$w] = $this->engine->dimensions($path);
                return $w ?: 1920;
            }
        }
        return 1024;
    }

    /**
     * Stash an LCP preload candidate. Bounded at 2 (browsers throttle
     * beyond that) — first writer wins.
     *
     * Best-effort dual emission: if the response headers are still open,
     * also emit an HTTP `Link: <…>; rel=preload` header so a late
     * `<picture>` rewrite inside the page body still buys us an LCP
     * preload (the wp_head emitter only catches images rendered before
     * `<?php wp_head(); ?>` ran).
     */
    private static function register_preload(int $attachment_id, string $srcset, string $sizes, string $type): void
    {
        if (isset(self::$preloads[$attachment_id])) {
            return;
        }
        if (count(self::$preloads) >= 2) {
            return;
        }
        if ($srcset === '') {
            return;
        }
        self::$preloads[$attachment_id] = [
            'srcset' => $srcset,
            'sizes'  => $sizes,
            'type'   => $type,
        ];

        // HTTP Link header fallback. The browser preload scanner honors
        // `Link: <url>; rel=preload; as=image; imagesrcset=…; imagesizes=…`
        // — and this fires regardless of where in the page the image is
        // rendered, so it covers themes that call wp_get_attachment_image()
        // deep in the body (like the EUPD hero carousel).
        if (!headers_sent()) {
            $value = sprintf(
                '<>; rel=preload; as=image; type="%s"; imagesrcset="%s"; imagesizes="%s"; fetchpriority="high"',
                addslashes($type),
                addslashes($srcset),
                addslashes($sizes)
            );
            // `false` to append rather than replace any prior Link header.
            @header('Link: ' . $value, false);
        }
    }

    /**
     * Hooked on wp_head priority 1 (before WP-emitted preloads) to give
     * the browser the LCP image URLs as early as possible.
     *
     * Note: this only catches images that were rendered BEFORE wp_head
     * fired. Most themes render their hero image deep in the body, in
     * which case the HTTP `Link:` header path in register_preload()
     * picks up the slack — but only when headers have not been flushed
     * yet. Themes that want a guaranteed `<link rel="preload">` should
     * call wp_get_attachment_image() with fetchpriority=high from
     * inside a wp_head action at a priority lower than 1.
     */
    public static function emit_preloads(): void
    {
        if (is_admin() || is_feed()) {
            return;
        }
        foreach (self::$preloads as $p) {
            // imagesrcset is the modern preload primitive — pairs with
            // imagesizes so the browser picks the right descriptor.
            printf(
                '<link rel="preload" as="image" type="%s" imagesrcset="%s" imagesizes="%s" fetchpriority="high">' . "\n",
                esc_attr($p['type']),
                esc_attr($p['srcset']),
                esc_attr($p['sizes'])
            );
        }
    }
}
