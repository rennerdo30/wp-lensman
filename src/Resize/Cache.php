<?php

declare(strict_types=1);

namespace Lensman\Resize;

use Lensman\Plugin;

/**
 * Disk-cache helpers. Cached variants live at:
 *
 *     wp-content/uploads/lensman/cache/<hash>/<width>.<ext>
 *
 * The hash is keyed by source path + mtime so a file replaced in place
 * (same name, new bytes) gets a fresh cache bucket and the daily sweep
 * eventually collects the orphan.
 */
final class Cache
{
    /**
     * @return array{path:string,url:string}
     */
    public function root(): array
    {
        $uploads = wp_upload_dir();
        $base    = trailingslashit($uploads['basedir']) . Plugin::CACHE_DIRNAME;
        $url     = trailingslashit($uploads['baseurl']) . Plugin::CACHE_DIRNAME;
        if (!is_dir($base)) {
            $this->ensure_dir($base);
            // Drop a marker so housekeeping tools can identify the dir.
            @file_put_contents($base . '/.lensman', "Lensman cache.\n");
            @chmod($base . '/.lensman', 0664);
        }
        return ['path' => $base, 'url' => $url];
    }

    /**
     * Create $path if missing and try hard to make it writable by the
     * webserver. Mirrors the strategy WordPress core uses for uploads:
     * 0775 directory perms + best-effort chgrp to a sensible web group.
     *
     * Best-effort throughout: any chmod / chgrp / chown call may fail
     * under hardened hosting and we never want that to fatal the boot.
     */
    public function ensure_dir(string $path): bool
    {
        if (!is_dir($path)) {
            wp_mkdir_p($path);
        }
        if (!is_dir($path)) {
            return false;
        }

        // 0775 so the running user can read+write+traverse and the
        // group (typically the webserver group) can also write.
        @chmod($path, 0775);

        // Best-effort group flip: try the common webserver groups in
        // order of likelihood for our Docker WP image. We deliberately
        // suppress errors because the current process may not own the
        // directory (e.g. it was originally created by root).
        foreach (['www-data', 'apache', 'nginx', 'http'] as $group) {
            if (function_exists('posix_getgrnam')) {
                $info = @posix_getgrnam($group);
                if ($info && @chgrp($path, $group)) {
                    break;
                }
            } else {
                if (@chgrp($path, $group)) {
                    break;
                }
            }
        }
        return true;
    }

    /**
     * Returns true if the cache root + cache subdir are both writable by
     * the current PHP process. When false, the caller is expected to
     * register an admin_notices warning and short-circuit rewriting for
     * this request so the front end does not emit URLs that 404.
     */
    public function is_writable(): bool
    {
        $root = $this->root();
        $sub  = $root['path'] . '/cache';
        if (!is_dir($sub)) {
            $this->ensure_dir($sub);
        }
        return is_dir($root['path']) && is_writable($root['path'])
            && is_dir($sub) && is_writable($sub);
    }

    /**
     * Returns true if the cache is usable. If not, registers an admin
     * notice telling the operator exactly which `chown` to run.
     *
     * This runs once per request from Plugin::boot(). When it returns
     * false, the front-end filters short-circuit so we do not emit
     * <picture> markup pointing at variants that cannot be written.
     */
    public function is_writable_or_warn(): bool
    {
        if ($this->is_writable()) {
            return true;
        }
        $root = $this->root();
        $path = $root['path'];
        add_action('admin_notices', static function () use ($path): void {
            if (!current_user_can('manage_options')) {
                return;
            }
            $cmd = sprintf('chown -R www-data:www-data %s && chmod -R 0775 %s', $path, $path);
            echo '<div class="notice notice-error"><p><strong>Lensman:</strong> '
                . esc_html__('Cache directory is not writable by the web server. Image rewriting is disabled for this request.', 'lensman')
                . '</p><p><code>' . esc_html($cmd) . '</code></p></div>';
        });
        return false;
    }

    public function bucket_key(string $source_path): string
    {
        $mtime = @filemtime($source_path) ?: 0;
        // Hash the absolute path + mtime so cache buckets survive across
        // /wp-content moves but bust when bytes change.
        return substr(hash('sha256', $source_path . '|' . $mtime), 0, 16);
    }

    /**
     * @return array{path:string,url:string}
     */
    public function variant_paths(string $source_path, int $width, string $ext): array
    {
        $root   = $this->root();
        $bucket = $this->bucket_key($source_path);
        $dir    = $root['path'] . '/cache/' . $bucket;
        $url    = $root['url']  . '/cache/' . $bucket;
        if (!is_dir($dir)) {
            $this->ensure_dir($dir);
        }
        $name = $width . '.' . ltrim($ext, '.');
        return [
            'path' => $dir . '/' . $name,
            'url'  => $url . '/' . $name,
        ];
    }

    /**
     * Acquire a non-blocking exclusive lock keyed by the variant path.
     * Returns the file handle to close on completion, or null when another
     * process already holds the lock (in which case the caller should
     * fall back to the source URL for this request).
     *
     * @return resource|null
     */
    public function lock(string $variant_path)
    {
        $lockfile = $variant_path . '.lock';
        $fh = @fopen($lockfile, 'c');
        if (!$fh) {
            return null;
        }
        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            @fclose($fh);
            return null;
        }
        return $fh;
    }

    /**
     * @param resource $fh
     */
    public function unlock($fh, string $variant_path): void
    {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        @unlink($variant_path . '.lock');
    }

    /**
     * @return array{count:int,bytes:int}
     */
    public function stats(): array
    {
        $root  = $this->root();
        $count = 0;
        $bytes = 0;
        $base  = $root['path'] . '/cache';
        if (!is_dir($base)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $count++;
                $bytes += $file->getSize();
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * Remove the entire variant tree. Called by "regenerate all".
     */
    public function flush(): void
    {
        $root = $this->root();
        $base = $root['path'] . '/cache';
        if (!is_dir($base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() || $file->isLink()) {
                @unlink($file->getPathname());
            } elseif ($file->isDir()) {
                @rmdir($file->getPathname());
            }
        }
    }
}
