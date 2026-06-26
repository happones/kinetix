<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

/**
 * The catalog of abilities (scopes) a personal access token may be granted
 * (key => human label). Seeded from `config('kinetix.tokens.scopes')` and/or
 * `KinetixTokens::scopes([...])`. Empty means tokens get full access (`*`).
 */
class TokenScopeRegistry
{
    /**
     * @var array<string, string>
     */
    protected array $scopes = [];

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
}
