<?php

declare(strict_types=1);

namespace Lensman\Admin;

use Lensman\Plugin;
use Lensman\Resize\Cache;
use Lensman\Resize\Engine;

/**
 * Settings UI under a top-level menu (Dashicons-camera) so the plugin
 * is one click from anywhere in wp-admin.
 *
 * Fields:
 *   - enable WebP / enable AVIF / enable on-the-fly resize
 *   - max master width
 *   - quality sliders (JPEG / WebP / AVIF)
 *   - srcset widths (CSV)
 *   - default sizes attribute
 *   - "Regenerate all" + cache stats
 */
final class SettingsPage
{
    private Engine $engine;
    private Cache $cache;

    public function __construct(Engine $engine, Cache $cache)
    {
        $this->engine = $engine;
        $this->cache  = $cache;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_lensman_regenerate', [$this, 'handle_regenerate']);
        add_action('admin_post_lensman_flush', [$this, 'handle_flush']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Lensman', 'lensman'),
            __('Lensman', 'lensman'),
            'manage_options',
            'lensman',
            [$this, 'render'],
            'dashicons-camera',
            81
        );
    }

    public function register_settings(): void
    {
        register_setting('lensman', Plugin::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => Plugin::DEFAULTS,
        ]);
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function sanitize($in): array
    {
        $in = is_array($in) ? $in : [];
        $clean = array_merge(Plugin::DEFAULTS, $in);
        $clean['enable_webp']      = !empty($in['enable_webp']) ? 1 : 0;
        $clean['enable_avif']      = !empty($in['enable_avif']) ? 1 : 0;
        $clean['enable_resize']    = !empty($in['enable_resize']) ? 1 : 0;
        $clean['max_master_width'] = max(640, (int) ($in['max_master_width'] ?? 2400));
        $clean['jpeg_quality']     = max(40, min(95, (int) ($in['jpeg_quality'] ?? 82)));
        $clean['webp_quality']     = max(40, min(95, (int) ($in['webp_quality'] ?? 80)));
        $clean['avif_quality']     = max(30, min(90, (int) ($in['avif_quality'] ?? 60)));
        $clean['srcset_widths']    = (string) ($in['srcset_widths'] ?? Plugin::DEFAULTS['srcset_widths']);
        $clean['sizes_attr']       = (string) ($in['sizes_attr'] ?? Plugin::DEFAULTS['sizes_attr']);
        return $clean;
    }

    public function handle_regenerate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'lensman'));
        }
        check_admin_referer('lensman_regenerate');
        $this->cache->flush();
        wp_schedule_single_event(time() + 5, Plugin::CRON_REGEN_HOOK);
        wp_safe_redirect(add_query_arg(['page' => 'lensman', 'regenerated' => 1], admin_url('admin.php')));
        exit;
    }

    public function handle_flush(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'lensman'));
        }
        check_admin_referer('lensman_flush');
        $this->cache->flush();
        wp_safe_redirect(add_query_arg(['page' => 'lensman', 'flushed' => 1], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = Plugin::settings();
        $stats    = $this->cache->stats();
        $last     = (int) get_option('lensman_last_regen', 0);
        $imagick  = $this->engine->has_imagick();
        $gd       = $this->engine->has_gd();
        $webp_ok  = $this->engine->format_supported('webp');
        $avif_ok  = $this->engine->format_supported('avif');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Lensman', 'lensman'); ?></h1>
            <p class="description">
                <?php esc_html_e('Generates WebP / AVIF variants and responsive srcset for uploaded images.', 'lensman'); ?>
            </p>

            <?php if (!empty($_GET['regenerated'])) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Regeneration scheduled. The background job will rebuild every cached variant in the next few minutes.', 'lensman'); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['flushed'])) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Cache flushed.', 'lensman'); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Environment', 'lensman'); ?></h2>
            <table class="widefat striped" style="max-width:680px">
                <tbody>
                    <tr><th><?php esc_html_e('Imagick', 'lensman'); ?></th><td><?php echo $imagick ? '<span style="color:#0a7">available</span>' : '<span style="color:#a40">missing</span>'; ?></td></tr>
                    <tr><th><?php esc_html_e('GD', 'lensman'); ?></th><td><?php echo $gd ? '<span style="color:#0a7">available</span>' : '<span style="color:#a40">missing</span>'; ?></td></tr>
                    <tr><th><?php esc_html_e('WebP support', 'lensman'); ?></th><td><?php echo $webp_ok ? '<span style="color:#0a7">yes</span>' : '<span style="color:#a40">no</span>'; ?></td></tr>
                    <tr><th><?php esc_html_e('AVIF support', 'lensman'); ?></th><td><?php echo $avif_ok ? '<span style="color:#0a7">yes</span>' : '<span style="color:#888">no (PHP 8.1+ with avif support required)</span>'; ?></td></tr>
                </tbody>
            </table>

            <form action="options.php" method="post" style="margin-top:24px">
                <?php settings_fields('lensman'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Generate WebP', 'lensman'); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[enable_webp]" value="1" <?php checked($settings['enable_webp'], 1); ?>>
                                <?php esc_html_e('Emit a WebP source for every image (recommended).', 'lensman'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Generate AVIF', 'lensman'); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[enable_avif]" value="1" <?php checked($settings['enable_avif'], 1); ?> <?php disabled(!$avif_ok); ?>>
                                <?php esc_html_e('Emit an AVIF source (smaller still, slower to encode).', 'lensman'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('On-the-fly resize', 'lensman'); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[enable_resize]" value="1" <?php checked($settings['enable_resize'], 1); ?>>
                                <?php esc_html_e('Downscale freshly uploaded images that exceed the max master width.', 'lensman'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lensman-max-master"><?php esc_html_e('Max master width', 'lensman'); ?></label></th>
                            <td>
                                <input id="lensman-max-master" type="number" min="640" max="6144" step="1"
                                    name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[max_master_width]"
                                    value="<?php echo esc_attr((string) $settings['max_master_width']); ?>"
                                    class="small-text"> px
                                <p class="description"><?php esc_html_e('Anything wider than this gets downscaled before WP creates its subsizes.', 'lensman'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lensman-jpeg-q"><?php esc_html_e('JPEG quality', 'lensman'); ?></label></th>
                            <td>
                                <input id="lensman-jpeg-q" type="range" min="60" max="95" step="1"
                                    name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[jpeg_quality]"
                                    value="<?php echo esc_attr((string) $settings['jpeg_quality']); ?>"
                                    oninput="this.nextElementSibling.textContent=this.value">
                                <output style="margin-left:8px"><?php echo esc_html((string) $settings['jpeg_quality']); ?></output>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lensman-webp-q"><?php esc_html_e('WebP quality', 'lensman'); ?></label></th>
                            <td>
                                <input id="lensman-webp-q" type="range" min="60" max="95" step="1"
                                    name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[webp_quality]"
                                    value="<?php echo esc_attr((string) $settings['webp_quality']); ?>"
                                    oninput="this.nextElementSibling.textContent=this.value">
                                <output style="margin-left:8px"><?php echo esc_html((string) $settings['webp_quality']); ?></output>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lensman-avif-q"><?php esc_html_e('AVIF quality', 'lensman'); ?></label></th>
                            <td>
                                <input id="lensman-avif-q" type="range" min="30" max="90" step="1"
                                    name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[avif_quality]"
                                    value="<?php echo esc_attr((string) $settings['avif_quality']); ?>"
                                    oninput="this.nextElementSibling.textContent=this.value">
                                <output style="margin-left:8px"><?php echo esc_html((string) $settings['avif_quality']); ?></output>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lensman-widths"><?php esc_html_e('Srcset widths', 'lensman'); ?></label></th>
                            <td>
                                <input id="lensman-widths" type="text" class="regular-text"
                                    name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[srcset_widths]"
                                    value="<?php echo esc_attr((string) $settings['srcset_widths']); ?>">
                                <p class="description"><?php esc_html_e('Comma-separated. Widths larger than the source are skipped automatically.', 'lensman'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="lensman-sizes"><?php esc_html_e('Default "sizes" attribute', 'lensman'); ?></label></th>
                            <td>
                                <input id="lensman-sizes" type="text" class="large-text"
                                    name="<?php echo esc_attr(Plugin::OPTION_KEY); ?>[sizes_attr]"
                                    value="<?php echo esc_attr((string) $settings['sizes_attr']); ?>">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Cache', 'lensman'); ?></h2>
            <p>
                <?php
                /* translators: 1: file count, 2: human-readable bytes */
                printf(
                    esc_html__('%1$s cached variants, %2$s on disk.', 'lensman'),
                    '<strong>' . esc_html(number_format_i18n($stats['count'])) . '</strong>',
                    '<strong>' . esc_html(size_format($stats['bytes'], 2) ?: '0') . '</strong>'
                );
                ?>
                <?php if ($last > 0) : ?>
                    <br>
                    <?php
                    /* translators: %s: human-readable duration */
                    printf(
                        esc_html__('Last full regeneration: %s ago.', 'lensman'),
                        esc_html(human_time_diff($last, time()))
                    );
                    ?>
                <?php endif; ?>
            </p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block;margin-right:8px">
                <?php wp_nonce_field('lensman_regenerate'); ?>
                <input type="hidden" name="action" value="lensman_regenerate">
                <?php submit_button(__('Regenerate all cached images', 'lensman'), 'primary', 'submit', false); ?>
            </form>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block">
                <?php wp_nonce_field('lensman_flush'); ?>
                <input type="hidden" name="action" value="lensman_flush">
                <?php submit_button(__('Flush cache', 'lensman'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
