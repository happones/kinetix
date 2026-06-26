<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

/**
 * Accumulates the registered {@see SettingsPage} classes (declared by the host
 * app via `KinetixSettings::pages([...])` or `config('kinetix.settings.pages')`),
 * and resolves a page by its route key. Bound as a singleton.
 */
class SettingsRegistry
{
    /**
     * @var array<int, class-string<SettingsPage>>
     */
    protected array $pages = [];

    /**
     * @param array<int, class-string<SettingsPage>> $pages
     */
    public function register(array $pages): void
    {
        foreach ($pages as $page) {
            if (! in_array($page, $this->pages, true)) {
                $this->pages[] = $page;
            }
        }
    }

    /**
     * @return array<int, class-string<SettingsPage>>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * Resolve the page class whose key matches, or null.
     *
     * @return class-string<SettingsPage>|null
     */
    public function find(string $key): ?string
    {
        foreach ($this->pages as $page) {
            if ($page::make()->key() === $key) {
                return $page;
            }
        }

        return null;
    }
}
