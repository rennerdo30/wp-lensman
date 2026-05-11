<?php

declare(strict_types=1);

namespace Lensman\Resize;

use Lensman\Plugin;

/**
 * Image processing. Wraps GD + Imagick so we work on either, prefers
 * Imagick when available because it tends to produce better WebP/AVIF.
 *
 * Public surface:
 *   - on_upload($upload): right after wp_handle_upload, downscale the
 *     master if it's huge and produce the standard srcset widths.
 *   - variant($source_path, $width, $format): returns ['path','url'] for
 *     a cached variant, generating it on demand.
 *   - regenerate_all(): cron-triggered full sweep of the media library.
 */
final class Engine
{
    /** @var array<string,mixed> */
    private array $settings;
    private Cache $cache;

    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(array $settings, Cache $cache)
    {
        $this->settings = $settings;
        $this->cache    = $cache;
    }

    public function has_imagick(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    public function has_gd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function format_supported(string $format): bool
    {
        $format = strtolower($format);
        if ($format === 'webp') {
            if ($this->has_imagick()) {
                $formats = \Imagick::queryFormats('WEBP');
                if (!empty($formats)) {
                    return true;
                }
            }
            return $this->has_gd() && function_exists('imagewebp');
        }
        if ($format === 'avif') {
            if ($this->has_imagick()) {
                $formats = \Imagick::queryFormats('AVIF');
                if (!empty($formats)) {
                    return true;
                }
            }
            return $this->has_gd() && function_exists('imageavif');
        }
        return in_array($format, ['jpeg', 'jpg', 'png'], true);
    }

    /**
     * Called from wp_handle_upload. Downscales master if huge, primes
     * the cache with the WebP / AVIF / srcset variants so the first
     * front-end render is a cache hit.
     *
     * @param array{file:string,url:string,type:string} $upload
     */
    public function on_upload(array $upload): void
    {
        if (empty($upload['file']) || !is_file($upload['file'])) {
            return;
        }
        $type = strtolower((string) ($upload['type'] ?? ''));
        if (!in_array($type, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
            return;
        }
        $path = (string) $upload['file'];

        // Downscale-in-place if the master is larger than the cap. We
        // overwrite the source so WP's own srcset machinery uses the
        // smaller bytes as its starting point.
        if (!empty($this->settings['enable_resize'])) {
            $cap = (int) $this->settings['max_master_width'];
            [$w, $h] = $this->dimensions($path);
            if ($w > $cap || $h > $cap) {
                $target = ($w >= $h) ? $cap : (int) round($cap * ($w / $h));
                $this->write_variant($path, $path, $target, $this->source_ext($path));
            }
        }

        // Prime the standard srcset widths + WebP variants so the first
        // page render doesn't pay the resize tax inline.
        $widths = Plugin::parse_widths((string) $this->settings['srcset_widths']);
        $ext    = $this->source_ext($path);

        foreach ($widths as $width) {
            $this->variant($path, $width, $ext);
            if (!empty($this->settings['enable_webp']) && $this->format_supported('webp')) {
                $this->variant($path, $width, 'webp');
            }
            if (!empty($this->settings['enable_avif']) && $this->format_supported('avif')) {
                $this->variant($path, $width, 'avif');
            }
        }
    }

    /**
     * Resolve a cached variant, generating it on demand. Returns null on
     * any failure so callers fall back to the original URL.
     *
     * @return array{path:string,url:string,width:int,height:int}|null
     */
    public function variant(string $source_path, int $width, string $ext): ?array
    {
        if (!is_file($source_path)) {
            return null;
        }
        $ext = strtolower(ltrim($ext, '.'));
        if ($ext === 'jpg') {
            $ext = 'jpeg';
        }
        // Don't upscale.
        [$src_w] = $this->dimensions($source_path);
        if ($src_w > 0 && $width >= $src_w && $ext === strtolower($this->source_ext($source_path))) {
            // Same format as source AND request >= native width — just hand
            // back the original.
            $uploads = wp_upload_dir();
            $rel     = ltrim(str_replace($uploads['basedir'], '', $source_path), '/');
            return [
                'path'   => $source_path,
                'url'    => trailingslashit($uploads['baseurl']) . $rel,
                'width'  => $src_w,
                'height' => $this->dimensions($source_path)[1],
            ];
        }
        if ($src_w > 0 && $width > $src_w) {
            $width = $src_w;
        }

        $paths = $this->cache->variant_paths($source_path, $width, $ext);
        if (is_file($paths['path']) && filesize($paths['path']) > 0) {
            [$vw, $vh] = $this->dimensions($paths['path']);
            return $paths + ['width' => $vw, 'height' => $vh];
        }

        // Lock per variant so two concurrent requests don't both race
        // through GD/Imagick on the same target file.
        $lock = $this->cache->lock($paths['path']);
        if ($lock === null) {
            // Someone else owns this; back off — caller falls back to source.
            return null;
        }
        try {
            // Re-check in case the other process finished while we waited.
            if (is_file($paths['path']) && filesize($paths['path']) > 0) {
                [$vw, $vh] = $this->dimensions($paths['path']);
                return $paths + ['width' => $vw, 'height' => $vh];
            }
            if (!$this->write_variant($source_path, $paths['path'], $width, $ext)) {
                return null;
            }
        } finally {
            $this->cache->unlock($lock, $paths['path']);
        }

        [$vw, $vh] = $this->dimensions($paths['path']);
        return $paths + ['width' => $vw, 'height' => $vh];
    }

    /**
     * Build the full <img>-ready descriptor for an attachment (or raw
     * source path): the best base variant + the srcset string.
     *
     * @return array{src:string,srcset:string,sizes:string,webp_srcset:?string,avif_srcset:?string,width:int,height:int}|null
     */
    public function descriptor(string $source_path, int $target_width): ?array
    {
        if (!is_file($source_path)) {
            return null;
        }
        $ext    = $this->source_ext($source_path);
        $widths = Plugin::parse_widths((string) $this->settings['srcset_widths']);
        [$src_w] = $this->dimensions($source_path);
        if ($src_w > 0) {
            $widths = array_values(array_filter($widths, static fn ($w) => $w <= $src_w));
            if (!in_array($src_w, $widths, true)) {
                $widths[] = $src_w;
            }
        }
        sort($widths, SORT_NUMERIC);
        if (!$widths) {
            return null;
        }

        // Pick the smallest width >= target as the `src` fallback so the
        // <img> still loads a sensibly-sized base on browsers that ignore
        // srcset.
        $pick = $widths[0];
        foreach ($widths as $w) {
            $pick = $w;
            if ($w >= $target_width) {
                break;
            }
        }

        $base = $this->variant($source_path, $pick, $ext);
        if (!$base) {
            return null;
        }
        $srcset = $this->build_srcset($source_path, $widths, $ext);
        $webp   = (!empty($this->settings['enable_webp']) && $this->format_supported('webp'))
            ? $this->build_srcset($source_path, $widths, 'webp')
            : null;
        $avif   = (!empty($this->settings['enable_avif']) && $this->format_supported('avif'))
            ? $this->build_srcset($source_path, $widths, 'avif')
            : null;

        return [
            'src'         => $base['url'],
            'srcset'      => $srcset,
            'sizes'       => (string) $this->settings['sizes_attr'],
            'webp_srcset' => $webp,
            'avif_srcset' => $avif,
            'width'       => $base['width'],
            'height'      => $base['height'],
        ];
    }

    /**
     * Wrap a single `<img …>` tag in a `<picture>` block containing
     * `<source type="image/avif">` + `<source type="image/webp">` from
     * the descriptor.  Returns the original tag if the descriptor does
     * not carry any next-gen sources (nothing to gain from wrapping).
     *
     * Shared by Filters\Content (the_content rewrite) and
     * Filters\AttachmentHtml (wp_get_attachment_image rewrite) so both
     * surfaces produce identical markup.
     *
     * @param array{src:string,srcset:string,sizes:string,webp_srcset:?string,avif_srcset:?string,width:int,height:int} $desc
     */
    public function build_picture_html(string $img_tag, array $desc): string
    {
        // Defensive: never double-wrap.
        if (stripos($img_tag, '<picture') !== false) {
            return $img_tag;
        }
        $sources = '';
        if (!empty($desc['avif_srcset'])) {
            $sources .= '<source type="image/avif" srcset="' . esc_attr((string) $desc['avif_srcset'])
                . '" sizes="' . esc_attr((string) $desc['sizes']) . '">';
        }
        if (!empty($desc['webp_srcset'])) {
            $sources .= '<source type="image/webp" srcset="' . esc_attr((string) $desc['webp_srcset'])
                . '" sizes="' . esc_attr((string) $desc['sizes']) . '">';
        }
        if ($sources === '') {
            return $img_tag;
        }
        return '<picture>' . $sources . $img_tag . '</picture>';
    }

    /**
     * @param int[] $widths
     */
    private function build_srcset(string $source_path, array $widths, string $ext): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $v = $this->variant($source_path, $w, $ext);
            if ($v) {
                $parts[] = $v['url'] . ' ' . $v['width'] . 'w';
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Walk the media library and re-prime cache for every JPEG/PNG.
     * Runs in cron context so it can take its time.
     */
    public function regenerate_all(): void
    {
        // Increase the runtime budget where allowed.
        @set_time_limit(0);

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        foreach ($query->posts as $id) {
            $path = get_attached_file((int) $id);
            if (is_string($path) && is_file($path)) {
                $this->on_upload([
                    'file' => $path,
                    'url'  => '',
                    'type' => 'image/' . $this->source_ext($path),
                ]);
            }
        }
        update_option('lensman_last_regen', time(), false);
    }

    /**
     * @return array{0:int,1:int} [width, height]
     */
    public function dimensions(string $path): array
    {
        $info = @getimagesize($path);
        if (!$info) {
            return [0, 0];
        }
        return [(int) $info[0], (int) $info[1]];
    }

    public function source_ext(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpg') {
            return 'jpeg';
        }
        return $ext;
    }

    /**
     * Resize $src to $width pixels wide, write as $ext to $dst. Returns
     * true on success.
     */
    private function write_variant(string $src, string $dst, int $width, string $ext): bool
    {
        $ext = strtolower($ext);
        if ($ext === 'jpg') {
            $ext = 'jpeg';
        }
        $dst_tmp = $dst . '.tmp';

        $ok = $this->has_imagick()
            ? $this->write_imagick($src, $dst_tmp, $width, $ext)
            : $this->write_gd($src, $dst_tmp, $width, $ext);

        if (!$ok || !is_file($dst_tmp) || filesize($dst_tmp) === 0) {
            @unlink($dst_tmp);
            return false;
        }
        // Atomic publish so a concurrent reader never sees a partial file.
        if (!@rename($dst_tmp, $dst)) {
            @unlink($dst_tmp);
            return false;
        }
        return true;
    }

    private function write_imagick(string $src, string $dst, int $width, string $ext): bool
    {
        try {
            $im = new \Imagick($src);
            $im->setImageOrientation($im->getImageOrientation());
            $im->stripImage();
            $h = (int) round($im->getImageHeight() * ($width / max(1, $im->getImageWidth())));
            $im->resizeImage($width, $h, \Imagick::FILTER_LANCZOS, 1);

            if ($ext === 'webp') {
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality((int) $this->settings['webp_quality']);
                $im->setOption('webp:method', '6');
            } elseif ($ext === 'avif') {
                $im->setImageFormat('avif');
                $im->setImageCompressionQuality((int) $this->settings['avif_quality']);
            } elseif ($ext === 'png') {
                $im->setImageFormat('png');
            } else { // jpeg
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality((int) $this->settings['jpeg_quality']);
                $im->setSamplingFactors(['2x2', '1x1', '1x1']);
                $im->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
            }
            $im->writeImage($dst);
            $im->clear();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function write_gd(string $src, string $dst, int $width, string $ext): bool
    {
        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }
        $src_mime = $info['mime'];
        $sw = (int) $info[0];
        $sh = (int) $info[1];
        if ($sw <= 0 || $sh <= 0) {
            return false;
        }
        $img = match ($src_mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($src) : false,
            'image/png'  => function_exists('imagecreatefrompng')  ? @imagecreatefrompng($src)  : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default      => false,
        };
        if (!$img) {
            return false;
        }
        $h = (int) round($sh * ($width / $sw));
        $dst_img = imagecreatetruecolor($width, $h);
        if (!$dst_img) {
            imagedestroy($img);
            return false;
        }
        // Preserve transparency for PNG output.
        if ($ext === 'png' || $ext === 'webp' || $ext === 'avif') {
            imagealphablending($dst_img, false);
            imagesavealpha($dst_img, true);
            $transparent = imagecolorallocatealpha($dst_img, 0, 0, 0, 127);
            imagefilledrectangle($dst_img, 0, 0, $width, $h, $transparent);
        }
        imagecopyresampled($dst_img, $img, 0, 0, 0, 0, $width, $h, $sw, $sh);

        $ok = false;
        if ($ext === 'webp' && function_exists('imagewebp')) {
            $ok = imagewebp($dst_img, $dst, (int) $this->settings['webp_quality']);
        } elseif ($ext === 'avif' && function_exists('imageavif')) {
            $ok = imageavif($dst_img, $dst, (int) $this->settings['avif_quality']);
        } elseif ($ext === 'png' && function_exists('imagepng')) {
            $ok = imagepng($dst_img, $dst, 6);
        } elseif (function_exists('imagejpeg')) {
            $ok = imagejpeg($dst_img, $dst, (int) $this->settings['jpeg_quality']);
        }
        imagedestroy($img);
        imagedestroy($dst_img);
        return (bool) $ok;
    }
}
