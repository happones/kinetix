<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Support\Carbon;

/**
 * Static entry point for announcements / "what's new". Publish entries from a
 * seeder, a deploy step, or anywhere:
 *
 *     KinetixAnnouncements::publish('v2.0 is here', 'Dark mode, faster search…', 'feature');
 */
class KinetixAnnouncements
{
    public static function manager(): AnnouncementManager
    {
        return app(AnnouncementManager::class);
    }

    public static function publish(string $title, string $body, string $level = 'info', ?Carbon $publishedAt = null): Announcement
    {
        return static::manager()->create($title, $body, $level, $publishedAt);
    }
}
