<?php

declare(strict_types=1);

namespace Happones\Kinetix\Sessions;

use Happones\Kinetix\Data\BrowserSessionData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads and prunes the authenticated user's browser sessions from Laravel's
 * `sessions` table. Only meaningful when SESSION_DRIVER=database; otherwise the
 * list is unavailable (sessions aren't persisted per-row).
 */
class BrowserSessionManager
{
    public function usesDatabaseDriver(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * The user's active sessions, current device first, newest activity next.
     *
     * @return array<int, BrowserSessionData>
     */
    public function for(Model $user, Request $request): array
    {
        if (! $this->usesDatabaseDriver()) {
            return [];
        }

        $currentId = $request->hasSession() ? $request->session()->getId() : null;

        return $this->query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($currentId): BrowserSessionData {
                $agent = UserAgentParser::parse($session->user_agent ?? null);

                return new BrowserSessionData(
                    id: (string) $session->id,
                    ipAddress: $session->ip_address ?? null,
                    browser: $agent['browser'],
                    platform: $agent['platform'],
                    device: $agent['device'],
                    isCurrentDevice: $currentId !== null && (string) $session->id === (string) $currentId,
                    lastActive: isset($session->last_activity)
                        ? Carbon::createFromTimestamp((int) $session->last_activity)->toAtomString()
                        : null,
                );
            })
            ->sortByDesc(fn (BrowserSessionData $s): int => $s->isCurrentDevice ? 1 : 0)
            ->values()
            ->all();
    }

    /**
     * Delete every session for the user except the current request's. Returns
     * the number of sessions removed.
     */
    public function logoutOthers(Model $user, Request $request): int
    {
        if (! $this->usesDatabaseDriver()) {
            return 0;
        }

        $currentId = $request->hasSession() ? $request->session()->getId() : '';

        return $this->query()
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentId)
            ->delete();
    }

    protected function query(): Builder
    {
        $connection = config('session.connection');
        $table      = (string) config('session.table', 'sessions');

        return DB::connection($connection)->table($table);
    }
}
