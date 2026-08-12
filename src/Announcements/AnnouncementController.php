<?php

declare(strict_types=1);

namespace Happones\Kinetix\Announcements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Two audiences in one controller: every user reads the published feed, marks
 * it seen and dismisses banners; whoever passes `manageKinetixAnnouncements`
 * also writes them — the feature was publish-from-code only, so shipping an
 * announcement meant a deploy.
 */
class AnnouncementController
{
    /** Levels are host-defined slugs; anything else is not a level. */
    protected const LEVEL_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    public function __construct(protected AnnouncementManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'announcements' => $this->manager->feed($user),
            'unread'        => $this->manager->unreadCount($user),
        ]);
    }

    /**
     * The banner feed: published entries this user hasn't dismissed.
     */
    public function banner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:10'],
            'levels' => ['sometimes', 'string', 'max:200'],
        ]);

        return response()->json([
            'announcements' => $this->manager->banner(
                $this->user($request),
                // Clamped, since the fallback comes from config rather than the
                // validated request.
                max(1, min(10, (int) ($validated['limit'] ?? config('kinetix.announcements.banner_limit', 3)))),
                $this->levels($validated['levels'] ?? null),
            ),
        ]);
    }

    /**
     * Hide one announcement from this user's banner. Resolved through the
     * team-scoped query, so an id from another tenant is a 404.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $user = $this->user($request);

        // Read by name, not as a method argument: scalar route parameters are
        // injected positionally, so with teams on the `{current_team}` segment
        // would land here instead of the announcement.
        $model = Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->published()
            ->whereKey($request->route('announcement'))
            ->first();

        abort_if($model === null, 404);

        $this->manager->dismiss($user, $model);

        return response()->json(['status' => 'success']);
    }

    public function seen(Request $request): JsonResponse
    {
        $this->manager->markSeen($this->user($request));

        return response()->json(['status' => 'success']);
    }

    /**
     * The authoring list: drafts and scheduled entries too, which the reader
     * feed hides. Newest first, with unpublished ones at the top since they are
     * what an editor came to finish.
     */
    public function manage(): JsonResponse
    {
        Gate::authorize('manageKinetixAnnouncements');

        $announcements = Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->orderByRaw('published_at is null desc')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Announcement $a): array => $this->editable($a))
            ->all();

        return response()->json([
            'announcements' => $announcements,
            // Inside a team, a platform-wide entry is read-only (see update()).
            'teamScoped' => Announcement::currentTeamId() !== null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageKinetixAnnouncements');

        $announcement = Announcement::query()->create([
            ...$this->validatedAnnouncement($request),
            ...Announcement::teamAttributes(),
        ]);

        return response()->json(['announcement' => $this->editable($announcement)], 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('manageKinetixAnnouncements');

        $announcement = $this->findForWriting($request);
        $announcement->update($this->validatedAnnouncement($request));

        return response()->json(['announcement' => $this->editable($announcement)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('manageKinetixAnnouncements');

        $announcement = $this->findForWriting($request);

        // The banner's dismissals point at a row that is about to vanish.
        AnnouncementDismissal::query()
            ->where('announcement_id', $announcement->getKey())
            ->delete();

        $announcement->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * An announcement this tenant may WRITE: its own. A platform-wide entry is
     * every tenant's, so editing it from inside one team would rewrite the
     * message for all of them — that is a platform-scope job. Another team's
     * row stays a 404; its existence is not leaked.
     */
    protected function findForWriting(Request $request): Announcement
    {
        $announcement = Announcement::query()
            ->forCurrentTeamOrGlobal()
            ->findOrFail($request->route('announcement'));

        abort_if(
            $announcement->isGlobal() && Announcement::currentTeamId() !== null,
            403,
            'This is a platform-wide announcement. Edit it outside a team scope.',
        );

        return $announcement;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedAnnouncement(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body'  => ['required', 'string', 'max:10000'],
            'level' => ['required', 'string', 'regex:'.static::LEVEL_PATTERN],
            // Null is a draft, a future date schedules it — both are how an
            // editor works ahead of a release.
            'published_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * The authoring shape: the raw columns an editor round-trips, not the
     * reader DTO (no `isNew`, and `publishedAt` keeps its date rather than
     * being hidden as a draft).
     *
     * @return array<string, mixed>
     */
    protected function editable(Announcement $announcement): array
    {
        $publishedAt = $announcement->published_at;

        return [
            'id'          => $announcement->getKey(),
            'title'       => $announcement->title,
            'body'        => $announcement->body,
            'level'       => $announcement->level,
            'publishedAt' => $publishedAt?->format(\DateTimeInterface::ATOM),
            'isGlobal'    => $announcement->isGlobal(),
            'status'      => match (true) {
                $publishedAt === null    => 'draft',
                $publishedAt->isFuture() => 'scheduled',
                default                  => 'published',
            },
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function levels(?string $levels): array
    {
        if ($levels === null || $levels === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $levels)),
            fn (string $level): bool => preg_match(static::LEVEL_PATTERN, $level) === 1,
        ));
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
