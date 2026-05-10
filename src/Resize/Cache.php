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
            wp_mkdir_p($base);
            // Drop a marker so housekeeping tools can identify the dir.
            @file_put_contents($base . '/.lensman', "Lensman cache.\n");
        }
        return ['path' => $base, 'url' => $url];
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
            wp_mkdir_p($dir);
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
