<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Reads and writes persisted settings, scoped to the active team (or global when
 * teams are off / no current team). Values are JSON-encoded; secrets can be
 * stored encrypted. A whole scope is loaded once and cached, and the cache is
 * invalidated on every write — so `KinetixSettings::get()` is cheap to call.
 * The cache holds the stored rows as they are in the table (an encrypted value
 * stays ciphertext); values are decoded into a per-process memo.
 *
 * A team inherits the global values: it reads its own value for a key, else
 * the global one. Writing in a team only ever writes that team's value, and
 * forgetting it brings the global one back.
 */
class SettingsManager
{
    /**
     * Per-request memo of each scope's own values, keyed by scope, so repeated
     * reads don't re-hit the cache. Shared with the managers forTeam() pins.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $memo = [];

    /**
     * Whether forTeam()/global() pinned the scope, instead of the request.
     */
    protected bool $pinned = false;

    protected int|string|null $pinnedTeam = null;

    /**
     * Drop the request memo — never the persistent cache. Called by the
     * state reset between Octane requests and queued jobs, so a value
     * another process wrote is read again. The managers forTeam() pins
     * share the memo by reference and see the reset too.
     *
     * @internal
     */
    public function flushMemo(): void
    {
        $this->memo = [];
    }

    /**
     * The same settings, scoped to one team (`null`: the global values)
     * whatever team the request serves: an admin panel, a job, the console.
     */
    public function forTeam(Model|int|string|null $team): static
    {
        $settings             = clone $this;
        $settings->memo       = &$this->memo;
        $settings->pinned     = true;
        $settings->pinnedTeam = $team instanceof Model ? $team->getKey() : $team;

        return $settings;
    }

    /**
     * The global values: what every team inherits.
     */
    public function global(): static
    {
        return $this->forTeam(null);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * All settings for the current scope as a `key => value` map: the global
     * values, with the team's own over them.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $team   = $this->teamId();
        $values = $this->scope(null);

        return $team === null ? $values : array_replace($values, $this->scope($team));
    }

    /**
     * Only the values stored in the current scope, without the global ones a
     * team inherits.
     *
     * @return array<string, mixed>
     */
    public function own(): array
    {
        return $this->scope($this->teamId());
    }

    /**
     * The values the current scope inherits: the global ones in a team,
     * nothing at the global level itself.
     *
     * @return array<string, mixed>
     */
    public function inherited(): array
    {
        return $this->teamId() === null ? [] : $this->scope(null);
    }

    public function set(string $key, mixed $value, bool $encrypted = false): void
    {
        $encoded = (string) json_encode($value);

        Setting::query()->updateOrCreate(
            ['team_id' => $this->teamId(), 'key' => $key],
            [
                'value'     => $encrypted ? Crypt::encryptString($encoded) : $encoded,
                'encrypted' => $encrypted,
            ],
        );

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()
            ->where('team_id', $this->teamId())
            ->where('key', $key)
            ->delete();

        $this->flush();
    }

    /**
     * Drop the cached/memoized values for the current scope.
     */
    public function flush(): void
    {
        $scope = $this->scopeKey($this->teamId());

        unset($this->memo[$scope]);

        if ($this->cacheEnabled()) {
            Cache::forget($this->cacheKey($scope));
            // The pre-0.275 entry held decrypted values.
            Cache::forget($this->legacyCacheKey($scope));
        }
    }

    /**
     * One scope's own values, memoized and cached.
     *
     * @return array<string, mixed>
     */
    protected function scope(int|string|null $team): array
    {
        $scope = $this->scopeKey($team);

        if (array_key_exists($scope, $this->memo)) {
            return $this->memo[$scope];
        }

        $loader = fn (): array => $this->rows($team);

        // A cache entry is whatever the store hands back: checked, not trusted.
        /** @var mixed $rows */
        $rows = $this->cacheEnabled()
            ? Cache::rememberForever($this->cacheKey($scope), function () use ($loader, $scope): array {
                // First load under the v2 key: drop the pre-0.275 entry, which
                // held this scope's values decrypted.
                Cache::forget($this->legacyCacheKey($scope));

                return $loader();
            })
            : $loader();

        $values = [];

        foreach (is_array($rows) ? $rows : [] as $key => $row) {
            $values[$key] = is_array($row) ? $this->decodeRaw($row[0] ?? null, (bool) ($row[1] ?? false)) : null;
        }

        return $this->memo[$scope] = $values;
    }

    /**
     * One scope's rows as stored: `key => [value, encrypted]`.
     *
     * @return array<string, array{0: string|null, 1: bool}>
     */
    protected function rows(int|string|null $team): array
    {
        return Setting::query()
            ->where('team_id', $team)
            ->get()
            ->mapWithKeys(static fn (Setting $setting): array => [$setting->key => [$setting->value, (bool) $setting->encrypted]])
            ->all();
    }

    protected function decode(Setting $setting): mixed
    {
        return $this->decodeRaw($setting->value, (bool) $setting->encrypted);
    }

    protected function decodeRaw(?string $raw, bool $encrypted): mixed
    {
        if ($raw === null) {
            return null;
        }

        if ($encrypted) {
            $raw = Crypt::decryptString($raw);
        }

        return json_decode($raw, true);
    }

    protected function teamId(): int|string|null
    {
        if (! $this->pinned) {
            return KinetixTeams::keyFor('settings');
        }

        return KinetixTeams::enabledFor('settings') ? $this->pinnedTeam : null;
    }

    protected function scopeKey(int|string|null $team): string
    {
        return (string) ($team ?? 'global');
    }

    protected function cacheKey(string $scope): string
    {
        return ((string) config('kinetix.settings.cache_key', 'kinetix.settings')).':v2:'.$scope;
    }

    protected function legacyCacheKey(string $scope): string
    {
        return ((string) config('kinetix.settings.cache_key', 'kinetix.settings')).':'.$scope;
    }

    protected function cacheEnabled(): bool
    {
        return (bool) config('kinetix.settings.cache', true);
    }
}
