<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\BulkAction;
use Happones\Kinetix\Actions\FormAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Illuminate\Support\Facades\Gate;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

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
 * - `state`: the action's own configuration — every property its subclass
 *   declares that the table's instance set differently from a fresh
 *   `make()` (`ArchivePosts::make()->reason('spam')`). The endpoint's
 *   instance gets it back ({@see instantiate()}), so `handle()` and `form()`
 *   see the action the table configured. Models travel as identifiers and
 *   are fetched again, as in a queued job; a value that can't travel (a
 *   closure set fluently) throws when the table renders instead of being
 *   lost on the way.
 */
final class SealedAction
{
    use SerializesAndRestoresModelIdentifiers;

    /**
     * @param class-string<Action> $class
     * @param list<string>|null    $grants
     * @param array<string, mixed> $state  property values by `DeclaringClass::property`
     */
    private function __construct(
        public readonly string $class,
        public readonly ?string $ability,
        public readonly ?string $arguments,
        public readonly bool $subjectBound,
        public readonly ?array $grants,
        public readonly array $state = [],
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
            'state'        => self::configuredState($action),
        ];
    }

    /**
     * The endpoint's instance of the action: built from its class like the
     * table's, then given back the configuration the table set on it.
     */
    public function instantiate(string $name): Action
    {
        $action = $this->class::make($name);

        foreach ($this->state as $key => $value) {
            [$declaringClass, $property] = explode('::', $key, 2) + [1 => ''];

            if (! is_a($action, $declaringClass) || ! property_exists($declaringClass, $property)) {
                continue;
            }

            (new ReflectionProperty($declaringClass, $property))
                ->setValue($action, $this->getRestoredPropertyValue($value));
        }

        return $action;
    }

    /**
     * The properties the action's subclasses declare, where the table's
     * instance differs from a fresh `make()` — what fluent configuration set.
     * The base classes' own properties (label, icon, modal chrome) are only
     * for rendering and stay behind.
     *
     * @return array<string, mixed>
     */
    private static function configuredState(Action $action): array
    {
        $fresh = $action::make($action->getName());
        $codec = new self($action::class, null, null, false, null);
        $state = [];

        for ($class = new ReflectionClass($action); $class !== false && ! self::isBaseClass($class->getName()); $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || ! $property->isInitialized($action)) {
                    continue;
                }

                $value = $property->getValue($action);

                if ($property->isInitialized($fresh) && self::sameSetting($value, $property->getValue($fresh))) {
                    continue;
                }

                $where = $class->getName().'::'.$property->getName();

                if ($property->isReadOnly()) {
                    throw new LogicException("[{$where}] is readonly, so the action's endpoint can't be given the value the table set. Set it inside the class.");
                }

                $sealed = $codec->getSerializedPropertyValue($value);

                try {
                    serialize($sealed);
                } catch (Throwable) {
                    throw new LogicException("[{$where}] holds a value that can't travel to the action's endpoint (a closure, or an object holding one). Keep fluent settings to scalars, arrays, enums, value objects or models, or set it inside the class.");
                }

                $state[$where] = $sealed;
            }
        }

        return $state;
    }

    /**
     * Whether a property still holds what a fresh `make()` gives it. Two
     * closures can't be compared; one there in both is taken as the class's
     * own (a fresh instance builds its own copy).
     */
    private static function sameSetting(mixed $value, mixed $default): bool
    {
        if ($value instanceof Closure || $default instanceof Closure) {
            return $value instanceof Closure && $default instanceof Closure;
        }

        if (is_object($value) && is_object($default)) {
            return $value::class === $default::class && $value == $default;
        }

        return $value === $default;
    }

    /**
     * @param class-string $class
     */
    private static function isBaseClass(string $class): bool
    {
        return in_array($class, [Action::class, BulkAction::class, FormAction::class], true);
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
        $state     = $payload['state']     ?? [];

        return new self(
            class: $class,
            ability: is_string($ability) ? $ability : null,
            arguments: is_string($arguments) ? $arguments : null,
            subjectBound: ($payload['subjectBound'] ?? false) === true,
            grants: is_array($grants)
                ? array_values(array_map(static fn (mixed $key): string => (string) $key, array_filter($grants, 'is_scalar')))
                : null,
            state: is_array($state) ? $state : [],
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
            // Without a record the ability is checked against the class — the
            // same check that decided whether the button showed.
            return $this->subjectBound
                || ($record !== null
                    ? $gate->allows($this->ability, $this->arguments ?? $record)
                    : Action::allowsAbility($this->ability, $this->arguments ?? $modelClass));
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
