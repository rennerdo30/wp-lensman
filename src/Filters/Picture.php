<?php

declare(strict_types=1);

namespace Lensman\Filters;

/**
 * Reserved hook surface for emitting bare <picture> markup outside of
 * the_content. Themes can call:
 *
 *   echo apply_filters('lensman_picture', '', $attachment_id, $args);
 *
 * to get a fully composed <picture><source><img></picture> block.
 *
 * v0.1.0 ships a passthrough; the heavy lifting lives in
 * Filters\Content::rewrite_tag(). Splitting it into a dedicated
 * emitter is the v0.2 milestone (themes will want WebP-first markup
 * for hero images that never enter the_content).
 */
final class Picture
{
    public function register(): void
    {
        // Placeholder filter registration so themes can detect plugin
        // presence via has_filter('lensman_picture').
        add_filter('lensman_picture', static fn ($html) => is_string($html) ? $html : '');
    }
}
