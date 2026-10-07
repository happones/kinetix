<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Support;

/**
 * Read access to the form's current state during a server-side recompute — the
 * `$get` a reactive field closure receives:
 *
 *     Select::make('state')
 *         ->options(fn (Get $get) => State::where('country', $get('country'))->pluck('name', 'id'));
 *
 * It is a thin, read-only view over the submitted values (dot-notation aware
 * for nested/repeater state via {@see data_get()}). It never mutates — that's
 * {@see Set}'s job — so a field's `options`/`visible`/`disabled` closure can't
 * accidentally rewrite the state it's being computed from.
 */
final class Get
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(private readonly array $state) {}

    /**
     * Read one field's value (dot-notation supported), or the whole state when
     * no key is given.
     */
    public function __invoke(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->state;
        }

        return data_get($this->state, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->state;
    }
}
