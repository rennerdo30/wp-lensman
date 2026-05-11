<?php

declare(strict_types=1);

namespace Lensman\Filters;

use Lensman\Resize\Engine;

/**
 * Walks `<img>` tags in post content (and in any string passed through
 * the lensman_render filter) and rewrites each into a `<picture>` block
 * with WebP / AVIF sources + a JPEG/PNG fallback `<img>` carrying a
 * proper srcset, sizes, loading=lazy, decoding=async.
 *
 * Only touches images that resolve to a local attachment so external
 * CDN images pass through untouched.
 */
final class Content
{
    private Engine $engine;
    /** @var array<string,mixed> */
    private array $settings;

    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(Engine $engine, array $settings)
    {
        $this->engine   = $engine;
        $this->settings = $settings;
    }

    public function register(): void
    {
        // Run after WP's own srcset injection (priority 10) so we get
        // the polished markup, not the raw <img>.
        add_filter('the_content', [$this, 'filter_html'], 20);
        add_filter('post_thumbnail_html', [$this, 'filter_html'], 20);
        add_filter('lensman_render', [$this, 'filter_html'], 10);
    }

    public function filter_html($html): string
    {
        if (!is_string($html) || $html === '' || is_admin() || is_feed()) {
            return is_string($html) ? $html : '';
        }
        if (strpos($html, '<img') === false) {
            return $html;
        }
        // If the input already contains <picture> blocks (e.g. it was
        // rendered by wp_get_attachment_image() which is now wrapped by
        // Filters\AttachmentHtml), skip the inner <img> tags inside each
        // existing <picture> so we never double-wrap.
        $skipped = [];
        if (stripos($html, '<picture') !== false) {
            if (preg_match_all('/<picture\b[^>]*>.*?<\/picture>/is', $html, $blocks, PREG_OFFSET_CAPTURE)) {
                foreach ($blocks[0] as $b) {
                    if (preg_match('/<img\b[^>]*>/i', $b[0], $im)) {
                        $skipped[$im[0]] = true;
                    }
                }
            }
        }
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $m) use ($skipped): string {
                if (isset($skipped[$m[0]])) {
                    return $m[0];
                }
                return $this->rewrite_tag($m[0]);
            },
            $html
        );
    }

    private function rewrite_tag(string $tag): string
    {
        if (!preg_match('/\bsrc=("|\')([^"\']+)\1/i', $tag, $sm)) {
            return $tag;
        }
        $src = html_entity_decode($sm[2], ENT_QUOTES);
        $path = $this->src_to_path($src);
        if (!$path || !is_file($path)) {
            return $tag;
        }
        // Honor file types we actually process.
        $mime = wp_check_filetype($path)['type'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $tag;
        }

        // Pull the rendered width to pick the right base variant.
        $target = 0;
        if (preg_match('/\bwidth=("|\')(\d+)\1/i', $tag, $wm)) {
            $target = (int) $wm[2];
        }
        if ($target === 0) {
            $target = 1024;
        }

        $desc = $this->engine->descriptor($path, $target);
        if (!$desc) {
            return $tag;
        }

        $tag = $this->replace_attr($tag, 'src', $desc['src']);
        $tag = $this->replace_attr($tag, 'srcset', $desc['srcset']);
        if (!preg_match('/\bsizes=/i', $tag)) {
            $tag = $this->insert_attr($tag, 'sizes', $desc['sizes']);
        }
        if (!preg_match('/\bloading=/i', $tag)) {
            $tag = $this->insert_attr($tag, 'loading', 'lazy');
        }
        if (!preg_match('/\bdecoding=/i', $tag)) {
            $tag = $this->insert_attr($tag, 'decoding', 'async');
        }

        return $this->engine->build_picture_html($tag, $desc);
    }

    private function replace_attr(string $tag, string $attr, string $value): string
    {
        $value = esc_attr($value);
        if (preg_match('/\b' . preg_quote($attr, '/') . '=("|\')[^"\']*\1/i', $tag)) {
            return (string) preg_replace(
                '/\b' . preg_quote($attr, '/') . '=("|\')[^"\']*\1/i',
                $attr . '="' . $value . '"',
                $tag,
                1
            );
        }
        return $this->insert_attr($tag, $attr, $value, false);
    }

    private function insert_attr(string $tag, string $attr, string $value, bool $escape = true): string
    {
        $value = $escape ? esc_attr($value) : $value;
        return (string) preg_replace(
            '/<img\b/i',
            '<img ' . $attr . '="' . $value . '"',
            $tag,
            1
        );
    }

    private function src_to_path(string $src): ?string
    {
        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'];
        $basedir = $uploads['basedir'];

        // Strip query / hash.
        $clean = preg_replace('/[?#].*$/', '', $src) ?? $src;

        // Protocol-agnostic match — replace http/https of siteurl.
        $home = home_url();
        foreach ([$baseurl, str_replace(['http://', 'https://'], '//', $baseurl)] as $needle) {
            if ($needle && str_starts_with($clean, $needle)) {
                $rel = ltrim(substr($clean, strlen($needle)), '/');
                $path = $basedir . '/' . $rel;
                return $path;
            }
        }
        // Relative URL — resolve against home.
        if (str_starts_with($clean, '/') && $home) {
            $parsed = parse_url($baseurl);
            $bpath  = $parsed['path'] ?? '/wp-content/uploads';
            if (str_starts_with($clean, $bpath . '/')) {
                $rel  = ltrim(substr($clean, strlen($bpath)), '/');
                return $basedir . '/' . $rel;
            }
        }
        // Host-mismatch fallback: an absolute URL on a different host
        // (e.g. localhost:8080 in content authored on a dev box, served
        // on prod through a reverse proxy) still resolves to a local
        // file if its path lines up with the uploads dir. We confirm
        // the file exists before accepting it so we never rewrite a
        // truly remote image.
        $parsed_clean = parse_url($clean);
        if (is_array($parsed_clean) && !empty($parsed_clean['path'])) {
            $parsed_base = parse_url($baseurl);
            $bpath = $parsed_base['path'] ?? '/wp-content/uploads';
            if (str_starts_with($parsed_clean['path'], $bpath . '/')) {
                $rel  = ltrim(substr($parsed_clean['path'], strlen($bpath)), '/');
                $path = $basedir . '/' . $rel;
                if (is_file($path)) {
                    return $path;
                }
            }
        }
        return null;
    }
}
