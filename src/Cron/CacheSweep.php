<?php

declare(strict_types=1);

namespace Lensman\Cron;

use Lensman\Plugin;
use Lensman\Resize\Cache;

/**
 * Daily orphan sweep. The bucket key includes the source mtime, so any
 * file whose source has changed (or been deleted) leaves its bucket
 * stranded. This sweep walks the cache tree and deletes buckets older
 * than the configured TTL (default 30 days) — gentle enough that a
 * cache flush on every save_post isn't necessary.
 */
final class CacheSweep
{
    private Cache $cache;
    private const TTL_SECONDS = 30 * DAY_IN_SECONDS;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function register(): void
    {
        add_action(Plugin::CRON_SWEEP_HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        $root = $this->cache->root();
        $base = $root['path'] . '/cache';
        if (!is_dir($base)) {
            return;
        }
        $cutoff = time() - self::TTL_SECONDS;
        foreach (new \DirectoryIterator($base) as $bucket) {
            if ($bucket->isDot() || !$bucket->isDir()) {
                continue;
            }
            // mtime of the bucket dir is bumped by every new variant
            // written into it; a "stale" bucket hasn't been touched in
            // a month, meaning either the source was replaced (and the
            // hash-bucket key now points at a fresh dir) or the source
            // was deleted.
            if ($bucket->getMTime() < $cutoff) {
                $this->rmtree($bucket->getPathname());
            }
        }
    }

    private function rmtree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() || $f->isLink()) {
                @unlink($f->getPathname());
            } elseif ($f->isDir()) {
                @rmdir($f->getPathname());
            }
        }
        @rmdir($dir);
    }
}
