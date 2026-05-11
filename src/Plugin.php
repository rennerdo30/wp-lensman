<?php

declare(strict_types=1);

namespace Lensman;

use Lensman\Admin\SettingsPage;
use Lensman\Cron\CacheSweep;
use Lensman\Filters\AttachmentHtml;
use Lensman\Filters\AttachmentImage;
use Lensman\Filters\Content;
use Lensman\Filters\Picture;
use Lensman\Resize\Cache;
use Lensman\Resize\Engine;

/**
 * Plugin orchestrator. Wires the resize engine, the cache, the
 * <img> / <picture> rewriter, and the admin settings page.
 *
 * The engine is intentionally lazy: filters resolve the engine + cache
 * on first call so an admin request that never renders an image pays
 * no cost.
 */
final class Plugin
{
    public const OPTION_KEY = 'lensman_settings';
    public const CACHE_DIRNAME = 'lensman';
    public const CRON_SWEEP_HOOK = 'lensman_cache_sweep';
    public const CRON_REGEN_HOOK = 'lensman_cache_regenerate';

    public const DEFAULTS = [
        'enable_webp'        => 1,
        'enable_avif'        => 0,
        'enable_resize'      => 1,
        'max_master_width'   => 2400,
        'jpeg_quality'       => 82,
        'webp_quality'       => 80,
        'avif_quality'       => 60,
        'srcset_widths'      => '320,480,768,1024,1440,1920',
        'sizes_attr'         => '(max-width: 600px) 100vw, (max-width: 1200px) 50vw, 33vw',
    ];

    public function boot(): void
    {
        load_plugin_textdomain('lensman', false, dirname(plugin_basename(LENSMAN_FILE)) . '/languages');

        $settings = self::settings();
        $cache    = new Cache();
        $engine   = new Engine($settings, $cache);

        // Check once per request whether the cache root is writable.
        // When it is not, we register an admin notice AND short-circuit
        // <picture> emission so we never ship HTML pointing at variant
        // URLs that 404. Internal cache::root() ensures the dir exists
        // and tries to self-heal permissions.
        $cache_writable = $cache->is_writable_or_warn();

        // Upload pipeline — generate variants the moment a JPEG/PNG lands
        // in the media library so the front-end rewrite has cache hits
        // from the very first render.
        add_filter('wp_handle_upload', static function (array $upload) use ($engine, $cache_writable) {
            if ($cache_writable) {
                $engine->on_upload($upload);
            }
            return $upload;
        }, 20);

        // wp_handle_upload only fires for user-driven uploads through
        // the media UI. `wp media import`, REST attachment inserts and
        // the EUPD seeder all reach wp_insert_attachment without ever
        // touching wp_handle_upload, leaving variants unprimed. Hooking
        // add_attachment here closes that gap.
        add_action('add_attachment', static function ($attachment_id) use ($engine, $cache_writable): void {
            if (!$cache_writable) {
                return;
            }
            $path = get_attached_file((int) $attachment_id);
            $mime = get_post_mime_type((int) $attachment_id);
            if (!is_string($path) || !is_file($path)) {
                return;
            }
            // Guard against re-entry. The on_upload pipeline can call
            // wp_update_attachment_metadata which in some plugin stacks
            // re-fires add_attachment, and we do not want to recurse.
            static $in_progress = [];
            $key = (int) $attachment_id;
            if (isset($in_progress[$key])) {
                return;
            }
            $in_progress[$key] = true;
            try {
                $engine->on_upload([
                    'file' => $path,
                    'url'  => (string) wp_get_attachment_url((int) $attachment_id),
                    'type' => (string) ($mime ?: ''),
                ]);
            } finally {
                unset($in_progress[$key]);
            }
        }, 20);

        // Front-end rewrites.
        (new AttachmentImage($engine, $settings))->register();
        (new AttachmentHtml($engine, $settings, $cache_writable))->register();
        (new Content($engine, $settings))->register();
        (new Picture())->register(); // currently a passive helper; reserved for future <picture> emission

        // LCP preload hints — emitted at wp_head priority 1 so the
        // browser can start the LCP fetch before parsing the carousel.
        add_action('wp_head', [AttachmentHtml::class, 'emit_preloads'], 1);

        // Cron + cache maintenance.
        (new CacheSweep($cache))->register();

        if (is_admin()) {
            (new SettingsPage($engine, $cache))->register();
        }

        // Background regeneration trigger fired from the admin "regenerate
        // all" button.  Runs in its own request via wp_schedule_single_event
        // so it doesn't block the admin response.
        add_action(self::CRON_REGEN_HOOK, [$engine, 'regenerate_all']);
    }

    public static function on_activate(): void
    {
        if (false === get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::DEFAULTS);
        }

        // Pre-create the cache dir on activation so we never hit the
        // "first variant write races against directory creation while
        // running as root in wp-cli" trap that produced the v0.1
        // permission bug.
        $cache = new Cache();
        $root  = $cache->root();
        $cache->ensure_dir($root['path']);
        $cache->ensure_dir($root['path'] . '/cache');

        if (!wp_next_scheduled(self::CRON_SWEEP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_SWEEP_HOOK);
        }
    }

    public static function on_deactivate(): void
    {
        $ts = wp_next_scheduled(self::CRON_SWEEP_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_SWEEP_HOOK);
        }
    }

    /**
     * @return array{
     *     enable_webp:int, enable_avif:int, enable_resize:int,
     *     max_master_width:int, jpeg_quality:int, webp_quality:int,
     *     avif_quality:int, srcset_widths:string, sizes_attr:string
     * }
     */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        $merged = is_array($stored) ? array_merge(self::DEFAULTS, $stored) : self::DEFAULTS;

        $merged['enable_webp']      = (int) (bool) $merged['enable_webp'];
        $merged['enable_avif']      = (int) (bool) $merged['enable_avif'];
        $merged['enable_resize']    = (int) (bool) $merged['enable_resize'];
        $merged['max_master_width'] = max(640, (int) $merged['max_master_width']);
        $merged['jpeg_quality']     = max(40, min(95, (int) $merged['jpeg_quality']));
        $merged['webp_quality']     = max(40, min(95, (int) $merged['webp_quality']));
        $merged['avif_quality']     = max(30, min(90, (int) $merged['avif_quality']));
        $merged['srcset_widths']    = (string) $merged['srcset_widths'];
        $merged['sizes_attr']       = (string) $merged['sizes_attr'];

        return $merged;
    }

    /**
     * Parse the comma-separated widths setting into a sorted, deduped
     * integer list.
     *
     * @return int[]
     */
    public static function parse_widths(string $csv): array
    {
        $out = [];
        foreach (preg_split('/[,\s]+/', $csv) ?: [] as $tok) {
            $n = (int) trim($tok);
            if ($n >= 80 && $n <= 4096) {
                $out[$n] = true;
            }
        }
        $out = array_keys($out);
        sort($out, SORT_NUMERIC);
        return $out;
    }
}
