<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\BulkAction;
use Happones\Kinetix\Actions\FormAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A server-side action ({@see BulkAction}, {@see FormAction}) as its table
 * sealed it into a signed descriptor: the class, plus the authorization the
 * endpoint re-checks.
 *
 * The endpoint rebuilds the action from its class, so whatever was set
 * fluently on the table's instance is gone by then. This record carries that
 * instance's gates across:
 *
 * - `ability`: a policy ability given to `authorize('…')`, re-checked per
 *   record (against the model class when there is no record). It replaces the
 *   table's default write ability for this action.
 * - `grants`: the record keys the action rendered for (record FormActions), or
 *   that a record-dependent `visible()`/`hidden()` closure allowed (bulk
 *   actions). A record outside them is refused, so a user can only run what
 *   the table showed them.
 */
final class SealedAction
{
    /**
     * @param class-string<Action> $class
     * @param list<string>|null    $grants
     */
    private function __construct(
        public readonly string $class,
        public readonly ?string $ability,
        public readonly ?string $arguments,
        public readonly bool $subjectBound,
        public readonly ?array $grants,
    ) {}

    /**
     * The payload to seal for an action.
     *
     * @param  list<string>|null    $grants
     * @return array<string, mixed>
     */
    public static function seal(Action $action, ?array $grants = null): array
    {
        $arguments = $action->getAuthorizationArguments();

        return [
            'class'   => $action::class,
            'ability' => $action->getAuthorizationAbility(),
            // A class-string subject travels with the ability. Any other
            // explicit subject (a model instance) was already checked when the
            // action rendered, so the endpoint doesn't need it again.
            'arguments'    => is_string($arguments) ? $arguments : null,
            'subjectBound' => $arguments !== null && ! is_string($arguments),
            'grants'       => $grants === null ? null : array_values(array_unique($grants)),
        ];
    }

    /**
     * Read a sealed action back. Null when the entry is malformed or its class
     * isn't a $base subclass — including the bare class string an older
     * release sealed, which carries no authorization and is refused.
     *
     * @param class-string<Action> $base
     */
    public static function fromPayload(mixed $payload, string $base): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $class = $payload['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, $base)) {
            return null;
        }

        $ability   = $payload['ability']   ?? null;
        $arguments = $payload['arguments'] ?? null;
        $grants    = $payload['grants']    ?? null;

        return new self(
            class: $class,
            ability: is_string($ability) ? $ability : null,
            arguments: is_string($arguments) ? $arguments : null,
            subjectBound: ($payload['subjectBound'] ?? false) === true,
            grants: is_array($grants)
                ? array_values(array_map(static fn (mixed $key): string => (string) $key, array_filter($grants, 'is_scalar')))
                : null,
        );
    }

    /**
     * Whether running the action on $record (null for a record-less toolbar
     * run) is allowed.
     *
     * @param class-string<Model> $modelClass
     * @param string|null         $tableAbility the table's writeAbility()
     */
    public function authorizes(?Model $record, string $modelClass, ?string $tableAbility): bool
    {
        if ($record !== null && $this->grants !== null && ! in_array((string) $record->getKey(), $this->grants, true)) {
            return false;
        }

        $gate = Gate::forUser(request()->user());

        if ($this->ability !== null) {
            return $this->subjectBound
                || $gate->allows($this->ability, $this->arguments ?? $record ?? $modelClass);
        }

        // No ability of its own: a record falls back to the table's write
        // ability, or `update` whenever the model has a policy. A record-less
        // run has no subject to check that against.
        if ($record === null) {
            return true;
        }

        $ability = $tableAbility ?? (Gate::getPolicyFor($modelClass) !== null ? 'update' : null);

        return $ability === null || $gate->allows($ability, $record);
    }
}
