<?php

declare(strict_types=1);

namespace Lensman\Filters;

use Lensman\Resize\Engine;

/**
 * Hooks WordPress's attachment-image pipeline so functions like
 * wp_get_attachment_image() / the_post_thumbnail() automatically pick
 * up the Lensman srcset.
 *
 * We DON'T touch the `src` returned from wp_get_attachment_image_src
 * because callers (themes, plugins) sometimes use it as a unique key.
 * Instead we hook `wp_get_attachment_image_attributes` to add `srcset`
 * + `sizes` and upgrade to a Lensman-cached URL.
 */
final class AttachmentImage
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
        // Priority 99 so we win against other srcset filters (jetpack etc).
        add_filter('wp_get_attachment_image_attributes', [$this, 'filter_attrs'], 99, 3);
    }

    /**
     * @param array<string,string> $attr
     * @param \WP_Post $attachment
     * @param string|int[] $size
     * @return array<string,string>
     */
    public function filter_attrs($attr, $attachment, $size): array
    {
        if (is_admin() || !is_array($attr) || empty($attr['src'])) {
            return is_array($attr) ? $attr : [];
        }
        $path = get_attached_file((int) $attachment->ID);
        if (!is_string($path) || !is_file($path)) {
            return $attr;
        }
        $mime = get_post_mime_type($attachment);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $attr;
        }
        // Best-guess target width from the size argument.
        $target = $this->target_width_for_size($size, $path);
        $desc = $this->engine->descriptor($path, $target);
        if (!$desc) {
            return $attr;
        }

        $attr['src']    = $desc['src'];
        $attr['srcset'] = $desc['srcset'];
        if (empty($attr['sizes'])) {
            $attr['sizes'] = $desc['sizes'];
        }
        if (empty($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        if (empty($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        return $attr;
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
}
