<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

/**
 * The catalog of abilities (scopes) a personal access token may be granted
 * (key => human label). Seeded from `config('kinetix.tokens.scopes')` and/or
 * `KinetixTokens::scopes([...])`. Empty means tokens get full access (`*`).
 *
 * Some of them can be named defaults (`config('kinetix.tokens.default_scopes')`
 * or `KinetixTokens::defaultScopes([...])`): the create form starts with them
 * ticked, typically the read-only ones.
 */
class TokenScopeRegistry
{
    /**
     * @var array<string, string>
     */
    protected array $scopes = [];

    /**
     * @var array<int, string>
     */
    protected array $defaults = [];

    /**
     * @param array<int|string, string> $scopes key=>label, or a plain list of keys
     */
    public function register(array $scopes): void
    {
        foreach ($scopes as $key => $label) {
            if (is_int($key)) {
                $this->scopes[$label] = $label;

                continue;
            }

            $this->scopes[$key] = $label;
        }
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->scopes;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->scopes);
    }

    /**
     * @param array<int, string> $keys
     */
    public function defaults(array $keys): void
    {
        $this->defaults = array_values(array_unique([...$this->defaults, ...$keys]));
    }

    /**
     * The default scopes that are in the catalog, in catalog order: a default
     * naming a scope nobody registered would tick a box that is not there.
     *
     * @return array<int, string>
     */
    public function defaultKeys(): array
    {
        return array_values(array_intersect($this->keys(), $this->defaults));
    }
}
