<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Reads and writes persisted settings, scoped to the active team (or global when
 * teams are off / no current team). Values are JSON-encoded; secrets can be
 * stored encrypted. A whole scope is loaded once and cached, and the cache is
 * invalidated on every write — so `KinetixSettings::get()` is cheap to call.
 */
class SettingsManager
{
    /**
     * Per-request memo, keyed by scope, so repeated reads don't re-hit the cache.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $memo = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * All settings for the current scope as a `key => value` map.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $scope = $this->scopeKey();

        if (array_key_exists($scope, $this->memo)) {
            return $this->memo[$scope];
        }

        $loader = fn (): array => $this->load();

        $values = $this->cacheEnabled()
            ? Cache::rememberForever($this->cacheKey(), $loader)
            : $loader();

        return $this->memo[$scope] = $values;
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
        unset($this->memo[$this->scopeKey()]);

        if ($this->cacheEnabled()) {
            Cache::forget($this->cacheKey());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        return Setting::query()
            ->where('team_id', $this->teamId())
            ->get()
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $this->decode($setting)])
            ->all();
    }

    protected function decode(Setting $setting): mixed
    {
        $raw = $setting->value;

        if ($raw === null) {
            return null;
        }

        if ($setting->encrypted) {
            $raw = Crypt::decryptString($raw);
        }

        return json_decode($raw, true);
    }

    protected function teamId(): int|string|null
    {
        if (! KinetixTeams::enabledFor('settings')) {
            return null;
        }

        return auth()->user()?->currentTeam?->getKey();
    }

    protected function scopeKey(): string
    {
        return (string) ($this->teamId() ?? 'global');
    }

    protected function cacheKey(): string
    {
        return ((string) config('kinetix.settings.cache_key', 'kinetix.settings')).':'.$this->scopeKey();
    }

    protected function cacheEnabled(): bool
    {
        return (bool) config('kinetix.settings.cache', true);
    }
}
