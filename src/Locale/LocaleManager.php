<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves, applies and persists the active locale for the self-service language
 * switcher. The choice is stored in the session and — when the user model has a
 * `locale` column — on the authenticated user so it survives across devices.
 */
class LocaleManager
{
    /**
     * The supported locales as `code => native label` pairs.
     *
     * @return array<string, string>
     */
    public function locales(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('kinetix.locale.locales', ['en' => 'English']);

        return $locales;
    }

    /**
     * The supported locales as a list of `{ code, label }` shapes for the UI.
     *
     * @return array<int, array{code: string, label: string}>
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->locales() as $code => $label) {
            $options[] = ['code' => $code, 'label' => $label];
        }

        return $options;
    }

    /**
     * Whether the given code is one of the supported locales.
     */
    public function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, $this->locales());
    }

    /**
     * The currently active application locale.
     */
    public function current(): string
    {
        return App::getLocale();
    }

    /**
     * Resolve the persisted locale for this request: the authenticated user's
     * stored locale first, then the session, then null (leave the default).
     */
    public function resolve(?Model $user = null): ?string
    {
        if ($user !== null && $this->storesOnUser()) {
            $stored = $user->getAttribute('locale');

            if (is_string($stored) && $this->isSupported($stored)) {
                return $stored;
            }
        }

        $session = session()->get($this->sessionKey());

        return $this->isSupported($session) && is_string($session) ? $session : null;
    }

    /**
     * Apply the resolved locale to the application for this request.
     */
    public function apply(?Model $user = null): void
    {
        $locale = $this->resolve($user);

        if ($locale !== null) {
            App::setLocale($locale);
        }
    }

    /**
     * Persist and apply a new locale. Stores it in the session and, when
     * supported, on the user's `locale` column. Ignores unsupported codes.
     */
    public function set(string $code, ?Model $user = null): bool
    {
        if (! $this->isSupported($code)) {
            return false;
        }

        session()->put($this->sessionKey(), $code);

        if ($user !== null && $this->storesOnUser()) {
            $user->setAttribute('locale', $code);
            $user->save();
        }

        App::setLocale($code);

        return true;
    }

    /**
     * Whether the user's `locale` column should be used for persistence.
     */
    protected function storesOnUser(): bool
    {
        if (! config('kinetix.locale.store_on_user', true)) {
            return false;
        }

        $user  = auth()->user();
        $table = $user instanceof Model ? $user->getTable() : 'users';

        return Schema::hasColumn($table, 'locale');
    }

    protected function sessionKey(): string
    {
        return (string) config('kinetix.locale.session_key', 'kinetix.locale');
    }
}
