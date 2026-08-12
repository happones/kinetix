<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Carbon\CarbonInterface;
use Happones\Kinetix\Data\AnnouncementData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists published announcements and tracks, per user, which are "new" (published
 * after the last time the user opened the feed) and which they have dismissed
 * from the banner.
 */
class AnnouncementManager
{
    /**
     * Read state resolved once per request — `feed()` and `unreadCount()` run
     * back to back on every index call and would otherwise repeat the query.
     *
     * @var array<string, array{team: CarbonInterface|null, global: CarbonInterface|null}>
     */
    protected array $seenCache = [];

    /**
     * The published feed for a user, newest first, with an `isNew` flag.
     *
     * @return array<int, AnnouncementData>
     */
    public function feed(Model $user, int $limit = 20): array
    {
        $seen = $this->seenAt($user);

        return Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->published()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $a): AnnouncementData => AnnouncementData::fromModel($a, $this->isNew($a, $seen)))
            ->all();
    }

    /**
     * The banner feed: published entries the user hasn't dismissed, newest
     * first. Optionally narrowed to given levels.
     *
     * @param  array<int, string>           $levels
     * @return array<int, AnnouncementData>
     */
    public function banner(Model $user, int $limit = 3, array $levels = []): array
    {
        $seen = $this->seenAt($user);

        return Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->published()
            ->when($levels !== [], fn (Builder $q): Builder => $q->whereIn('level', $levels))
            ->whereNotExists($this->dismissedByUser($user))
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $a): AnnouncementData => AnnouncementData::fromModel($a, $this->isNew($a, $seen)))
            ->all();
    }

    /**
     * What every Inertia response carries: the unread badge and the banner
     * feed. Both components would otherwise fetch on mount, which costs a
     * round-trip per navigation in an app whose layout is re-created per page.
     *
     * @return array{unread: int, bannerLimit: int, banner: array<int, AnnouncementData>}
     */
    public function sharedState(Model $user): array
    {
        $limit = max(1, min(10, (int) config('kinetix.announcements.banner_limit', 3)));

        return [
            'unread' => $this->unreadCount($user),
            // The banner only hydrates from this when it isn't narrowed with
            // its own limit/levels, so it has to say what shape it holds.
            'bannerLimit' => $limit,
            'banner'      => $this->banner($user, $limit),
        ];
    }

    /**
     * How many published announcements the user hasn't seen yet.
     */
    public function unreadCount(Model $user): int
    {
        $seen  = $this->seenAt($user);
        $query = Announcement::query()->forCurrentTeamOrGlobal()->published();

        if (! $this->readStateIsSplit()) {
            return $this->onlyPublishedAfter($query, $seen['global'])->count();
        }

        return $query
            ->where(function (Builder $q) use ($seen): void {
                $q
                    ->where(fn (Builder $global): Builder => $this->onlyPublishedAfter($global->whereNull('team_id'), $seen['global']))
                    ->orWhere(fn (Builder $team): Builder => $this->onlyPublishedAfter($team->whereNotNull('team_id'), $seen['team']));
            })
            ->count();
    }

    /**
     * Mark the feed as seen for the user (clears their unread count).
     *
     * Scoped to the team the request is serving; the platform-wide entries are
     * marked seen on their own row, so reading them once clears them in every
     * team instead of following the user around.
     */
    public function markSeen(Model $user): void
    {
        $now = now();

        AnnouncementView::query()->updateOrCreate(
            ['user_id' => $user->getKey()] + AnnouncementView::teamAttributes(),
            ['seen_at' => $now],
        );

        if ($this->readStateIsSplit()) {
            AnnouncementView::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'team_id' => null],
                ['seen_at' => $now],
            );
        }

        unset($this->seenCache[$this->cacheKey($user)]);
    }

    /**
     * Hide one announcement from this user's banner, permanently.
     */
    public function dismiss(Model $user, Announcement $announcement): AnnouncementDismissal
    {
        $attributes = ['dismissed_at' => now()];

        if (KinetixTeams::enabledFor('announcements')) {
            $attributes['team_id'] = $announcement->getAttribute('team_id');
        }

        return AnnouncementDismissal::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'announcement_id' => $announcement->getKey()],
            $attributes,
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

    /**
     * The user's read state: when they last opened THIS team's feed, and when
     * they last read the platform-wide entries.
     *
     * @return array{team: CarbonInterface|null, global: CarbonInterface|null}
     */
    protected function seenAt(Model $user): array
    {
        $key = $this->cacheKey($user);

        if (array_key_exists($key, $this->seenCache)) {
            return $this->seenCache[$key];
        }

        $rows = AnnouncementView::query()
            ->where('user_id', $user->getKey())
            ->get();

        if (! $this->readStateIsSplit()) {
            $seenAt = $this->latestSeenAt($rows->all());

            return $this->seenCache[$key] = ['team' => $seenAt, 'global' => $seenAt];
        }

        $teamId = KinetixTeams::currentTeamKey();

        return $this->seenCache[$key] = [
            'team' => $this->latestSeenAt(
                $rows->filter(fn (AnnouncementView $v): bool => (string) $v->getAttribute('team_id') === (string) $teamId)->all(),
            ),
            'global' => $this->latestSeenAt(
                $rows->filter(fn (AnnouncementView $v): bool => $v->getAttribute('team_id') === null)->all(),
            ),
        ];
    }

    /**
     * Whether team and platform-wide entries track read state separately —
     * they only do inside a team's request, since without one every row IS the
     * global row.
     */
    protected function readStateIsSplit(): bool
    {
        return KinetixTeams::enabledFor('announcements')
            && KinetixTeams::currentTeamKey() !== null;
    }

    /**
     * Whether the entry was published after the user last read its pool.
     *
     * @param array{team: CarbonInterface|null, global: CarbonInterface|null} $seen
     */
    protected function isNew(Announcement $announcement, array $seen): bool
    {
        $since = $announcement->isGlobal() ? $seen['global'] : $seen['team'];

        return $since === null
            || ($announcement->published_at !== null && $announcement->published_at->gt($since));
    }

    /**
     * @param  Builder<Announcement> $query
     * @return Builder<Announcement>
     */
    protected function onlyPublishedAfter(Builder $query, ?CarbonInterface $seenAt): Builder
    {
        return $seenAt === null ? $query : $query->where('published_at', '>', $seenAt);
    }

    /**
     * The "this user already dismissed it" existence check, served by the
     * dismissals table's (user_id, announcement_id) unique index.
     *
     * @return \Closure(QueryBuilder): void
     */
    protected function dismissedByUser(Model $user): \Closure
    {
        return function (QueryBuilder $query) use ($user): void {
            $query
                ->selectRaw('1')
                ->from('kinetix_announcement_dismissals')
                ->whereColumn('kinetix_announcement_dismissals.announcement_id', 'kinetix_announcements.id')
                ->where('kinetix_announcement_dismissals.user_id', $user->getKey());
        };
    }

    /**
     * @param array<int, AnnouncementView> $rows
     */
    protected function latestSeenAt(array $rows): ?CarbonInterface
    {
        $latest = null;

        foreach ($rows as $row) {
            $seenAt = $row->seen_at;

            if ($seenAt !== null && ($latest === null || $seenAt->gt($latest))) {
                $latest = $seenAt;
            }
        }

        return $latest;
    }

    protected function cacheKey(Model $user): string
    {
        return $user->getKey().'|'.KinetixTeams::currentTeamKey();
    }
}
