<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use ArgumentCountError;
use Closure;
use Error;
use ErrorException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use ReflectionFunction;
use Throwable;
use TypeError;

/**
 * Visibility + Laravel-policy authorization for actions, evaluated server-side.
 *
 * Components that don't pass these checks are omitted from the serialized payload
 * entirely, so the frontend never receives (and cannot reveal) them.
 */
trait HasAuthorization
{
    protected bool|Closure $isVisible = true;

    protected bool|Closure $isHidden = false;

    protected string|Closure|bool|null $authorizeUsing = null;

    protected mixed $authorizeArguments = null;

    protected ?string $canAbility = null;

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    /**
     * Authorize via a Laravel policy ability, a boolean, or a closure.
     *
     * - string: checks `Gate::allows($ability, $subject)` where $subject is the
     *   explicit $arguments, else the contextual record.
     * - Closure: receives the record and returns a boolean.
     * - bool: a static gate.
     */
    public function authorize(string|Closure|bool $ability, mixed $arguments = null): static
    {
        $this->authorizeUsing     = $ability;
        $this->authorizeArguments = $arguments;

        return $this;
    }

    /**
     * Gate the item on a PERMISSION key from the Kinetix registry (e.g.
     * `employees.viewSalary`), checked against the authenticated user with no
     * subject at serialization time. Unlike {@see authorize()} — which defers
     * record-bound policy abilities when no record is available — `can()`
     * never defers: a denied form field, infolist entry or table column is
     * stripped from schemas, validation rules, state AND row payloads, so the
     * gated data never leaves the server.
     */
    /**
     * Whether authorize() was configured — containers (relation managers) use
     * this to add a default policy gate without overriding an explicit one.
     */
    public function hasAuthorization(): bool
    {
        return $this->authorizeUsing !== null;
    }

    public function can(string $ability): static
    {
        $this->canAbility = $ability;

        return $this;
    }

    protected function passesCan(): bool
    {
        if ($this->canAbility === null) {
            return true;
        }

        return Gate::forUser(auth()->user())->allows($this->canAbility);
    }

    protected function passesVisibility(?Model $record = null): bool
    {
        // Record-dependent closures are deferred when there's no record (e.g. the
        // record-action template pass), so they don't wrongly drop the actions
        // column. A closure that doesn't need a record (`fn () => …`) runs in
        // every pass — deferring it meant it never ran where no record exists.
        if ($this->isHidden instanceof Closure) {
            if ($this->canEvaluateGate($this->isHidden, $record) && ($this->isHidden)($record)) {
                return false;
            }
        } elseif ($this->isHidden) {
            return false;
        }

        if ($this->isVisible instanceof Closure) {
            if ($this->canEvaluateGate($this->isVisible, $record) && ! ($this->isVisible)($record)) {
                return false;
            }
        } elseif (! $this->isVisible) {
            return false;
        }

        return true;
    }

    /**
     * Visibility for something that never gets a per-record pass — a table
     * column, a toolbar action. A visible()/hidden() closure runs NOW, with the
     * record if there is one (else null), instead of being deferred to a pass
     * that will never come. A closure typed for a record it can't receive
     * (`fn (Post $record)` with no record) fails closed.
     */
    public function passesVisibilityWithoutDeferral(?Model $record = null): bool
    {
        $hidden = $this->isHidden instanceof Closure
            ? (self::runGateWith($this->isHidden, $record) ?? true)
            : $this->isHidden;

        if ($hidden) {
            return false;
        }

        return $this->isVisible instanceof Closure
            ? (self::runGateWith($this->isVisible, $record) ?? false)
            : $this->isVisible;
    }

    /**
     * Whether this item renders where it never gets a record — a toolbar or
     * footer action — judged exactly as a record-less endpoint judges it:
     * visibility closures run now ({@see passesVisibilityWithoutDeferral()}),
     * an `authorize()` closure runs with no record (failing closed when it
     * needs one), and a policy ability is checked against its explicit
     * subject, else `$modelClass`. Nothing is deferred to a per-record pass,
     * because none will come.
     *
     * @param class-string<Model>|null $modelClass the table's model, the subject of a record-less ability
     */
    public function shouldRenderWithoutRecord(?string $modelClass = null): bool
    {
        return $this->passesVisibilityWithoutDeferral()
            && $this->passesCan()
            && $this->passesAuthorizationWithoutRecord($modelClass);
    }

    /**
     * @param class-string<Model>|null $modelClass
     */
    protected function passesAuthorizationWithoutRecord(?string $modelClass): bool
    {
        if ($this->authorizeUsing === null || is_bool($this->authorizeUsing)) {
            return $this->authorizeUsing ?? true;
        }

        if ($this->authorizeUsing instanceof Closure) {
            return self::runGateWith($this->authorizeUsing, null) ?? false;
        }

        $subject = $this->authorizeArguments ?? $modelClass;

        // Outside a table there's no class to check against: the ability
        // stays deferred, as in a record-less template pass.
        if ($subject === null) {
            return true;
        }

        return self::allowsAbility($this->authorizeUsing, $subject);
    }

    /**
     * `Gate::allows()` for the current user, where an ability that needs a
     * model instance — asked about the class alone — denies instead of
     * throwing: a policy's `publish(User $user, Post $post)` or a
     * `Gate::define('publish', fn (User $user, Post $post) => …)` given
     * `Post::class`.
     */
    public static function allowsAbility(string $ability, mixed $subject): bool
    {
        try {
            return Gate::forUser(auth()->user())->allows($ability, $subject);
        } catch (TypeError $e) {
            // A policy method missing its model argument, or a
            // `Gate::define()` closure typed for a model given the class name.
            if ($e instanceof ArgumentCountError || is_string($subject)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Run a gate closure with the given record, or null when it can't run
     * without one: its first parameter is typed for a record, or it uses the
     * record it didn't get (`fn ($record) => $record->isDraft()`). That one
     * fails closed instead of taking the whole page down. Using the missing
     * record (a PHP `Error`, or a warning raised as an `ErrorException`) is
     * expected in a toolbar, so it isn't reported — it would be, on every
     * render; an exception the gate throws for another reason is.
     */
    private static function runGateWith(Closure $gate, ?Model $record): ?bool
    {
        $first = (new ReflectionFunction($gate))->getParameters()[0] ?? null;

        if (
            $record === null
            && $first !== null
            && ! $first->isOptional()
            && $first->hasType()
            && ! $first->allowsNull()
        ) {
            return null;
        }

        if ($record !== null) {
            return (bool) $gate($record);
        }

        try {
            return (bool) $gate($record);
        } catch (Error|ErrorException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Whether a gate closure can run in this pass: always with a record, and
     * without one only when it doesn't need it.
     */
    protected function canEvaluateGate(Closure $gate, ?Model $record): bool
    {
        return $record !== null || ! self::gateNeedsRecord($gate);
    }

    /**
     * A gate closure needs a record when its first parameter is required and
     * can't take null — `fn ($record)`, `fn (Post $record)`. `fn ()`,
     * `fn (?Post $record)` and `fn ($record = null)` run without one.
     */
    protected static function gateNeedsRecord(Closure $gate): bool
    {
        $parameters = (new ReflectionFunction($gate))->getParameters();

        if ($parameters === []) {
            return false;
        }

        $first = $parameters[0];

        if ($first->isOptional()) {
            return false;
        }

        return ! ($first->hasType() && $first->allowsNull());
    }

    /**
     * Whether a visible()/hidden()/authorize() closure can look at a record —
     * `fn (Post $record)`, but also `fn (?Post $record)` and
     * `fn ($record = null)`, which pass the record-less pass with null and
     * may still refuse a given row. A server-side endpoint must not take that
     * pass as proof the gate allows every record.
     */
    public function hasRecordGates(): bool
    {
        foreach ([$this->isVisible, $this->isHidden, $this->authorizeUsing] as $gate) {
            if ($gate instanceof Closure && (new ReflectionFunction($gate))->getNumberOfParameters() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The policy ability passed to {@see authorize()} as a string, if any.
     */
    public function getAuthorizationAbility(): ?string
    {
        return is_string($this->authorizeUsing) ? $this->authorizeUsing : null;
    }

    /**
     * The explicit subject passed to {@see authorize()} alongside the ability.
     */
    public function getAuthorizationArguments(): mixed
    {
        return $this->authorizeArguments;
    }

    protected function passesAuthorization(?Model $record = null): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        if (is_bool($this->authorizeUsing)) {
            return $this->authorizeUsing;
        }

        // Like a visibility closure, one that needs a record is deferred to
        // the per-record pass when there is none.
        if ($this->authorizeUsing instanceof Closure) {
            return ! $this->canEvaluateGate($this->authorizeUsing, $record)
                || (bool) ($this->authorizeUsing)($record);
        }

        $subject = $this->authorizeArguments ?? $record;

        // Without a subject (e.g. serializing a record-action template with no row),
        // policy abilities cannot be evaluated yet — defer to the per-record pass.
        if ($subject === null) {
            return true;
        }

        return Gate::allows($this->authorizeUsing, $subject);
    }

    /**
     * Whether this item should be serialized (visible AND authorized).
     */
    public function shouldRender(?Model $record = null): bool
    {
        return $this->passesVisibility($record)
            && $this->passesCan()
            && $this->passesAuthorization($record);
    }
}
