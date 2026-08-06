<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

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
        // record-action template pass), so they don't wrongly drop the actions column.
        if ($this->isHidden instanceof Closure) {
            if ($record !== null && ($this->isHidden)($record)) {
                return false;
            }
        } elseif ($this->isHidden) {
            return false;
        }

        if ($this->isVisible instanceof Closure) {
            if ($record !== null && ! ($this->isVisible)($record)) {
                return false;
            }
        } elseif (! $this->isVisible) {
            return false;
        }

        return true;
    }

    protected function passesAuthorization(?Model $record = null): bool
    {
        if ($this->authorizeUsing === null) {
            return true;
        }

        if (is_bool($this->authorizeUsing)) {
            return $this->authorizeUsing;
        }

        if ($this->authorizeUsing instanceof Closure) {
            return (bool) ($this->authorizeUsing)($record);
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
