<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Carbon\CarbonInterface;

/**
 * Static entry point for announcements / "what's new". Publish entries from a
 * seeder, a deploy step, or anywhere:
 *
 *     KinetixAnnouncements::publish('v2.0 is here', 'Dark mode, faster search…', 'feature');
 *
 * Pass `$expiresAt` for news with a shelf life ("maintenance on Sunday") — it
 * leaves every feed on its own, instead of sitting there long after the fact.
 */
class KinetixAnnouncements
{
    public static function manager(): AnnouncementManager
    {
        return app(AnnouncementManager::class);
    }

    /**
     * Publish to the active team (or globally in a single-tenant app).
     */
    public static function publish(string $title, string $body, string $level = 'info', ?CarbonInterface $publishedAt = null, ?CarbonInterface $expiresAt = null): Announcement
    {
        return static::manager()->create($title, $body, $level, $publishedAt, expiresAt: $expiresAt);
    }

    /**
     * Publish a platform-wide announcement that every team's feed shows —
     * the usual choice from a deploy step or seeder, which has no team context.
     */
    public static function publishGlobally(string $title, string $body, string $level = 'info', ?CarbonInterface $publishedAt = null, ?CarbonInterface $expiresAt = null): Announcement
    {
        return static::manager()->create($title, $body, $level, $publishedAt, global: true, expiresAt: $expiresAt);
    }
}
