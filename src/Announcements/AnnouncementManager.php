<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Carbon\CarbonInterface;
use Happones\Kinetix\Data\AnnouncementData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists published announcements and tracks, per user, which are "new" (published
 * after the last time the user opened the feed).
 */
class AnnouncementManager
{
    /**
     * The published feed for a user, newest first, with an `isNew` flag.
     *
     * @return array<int, AnnouncementData>
     */
    public function feed(Model $user, int $limit = 20): array
    {
        $seenAt = $this->seenAt($user);

        return Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $a): AnnouncementData => AnnouncementData::fromModel(
                $a,
                $seenAt === null || ($a->published_at !== null && $a->published_at->gt($seenAt)),
            ))
            ->all();
    }

    /**
     * How many published announcements the user hasn't seen yet.
     */
    public function unreadCount(Model $user): int
    {
        $seenAt = $this->seenAt($user);

        return Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->when($seenAt !== null, fn ($q) => $q->where('published_at', '>', $seenAt))
            ->count();
    }

    /**
     * Mark the feed as seen for the user (clears their unread count).
     */
    public function markSeen(Model $user): void
    {
        AnnouncementView::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['seen_at' => now()],
        );
    }

    /**
     * Publish an announcement (defaults to publishing immediately).
     *
     * Scoped to the active team when announcements are team-scoped. Pass
     * `global: true` for a platform-wide entry — a product update every tenant
     * should read — which is what a deploy step or seeder usually wants, since
     * neither runs inside a team's request.
     */
    public function create(
        string $title,
        string $body,
        string $level = 'info',
        ?CarbonInterface $publishedAt = null,
        bool $global = false,
    ): Announcement {
        $attributes = [
            'title'        => $title,
            'body'         => $body,
            'level'        => $level,
            'published_at' => $publishedAt ?? now(),
        ];

        if (KinetixTeams::enabledFor('announcements')) {
            $attributes['team_id'] = $global ? null : Announcement::currentTeamId();
        }

        return Announcement::query()->create($attributes);
    }

    protected function seenAt(Model $user): ?CarbonInterface
    {
        $view = AnnouncementView::query()->where('user_id', $user->getKey())->first();

        if ($view === null) {
            return null;
        }

        return $view->seen_at;
    }
}
