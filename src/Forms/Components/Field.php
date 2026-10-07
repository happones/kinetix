<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Forms\Support\Get;
use Happones\Kinetix\Forms\Support\Set;
use Illuminate\Database\Eloquent\Model;

abstract class Field extends Component
{
    protected string $name;

    protected mixed $label = null;

    protected mixed $defaultValue = null;

    protected mixed $isDisabled = false;

    protected mixed $placeholder = null;

    protected bool $isLive = false;

    protected ?int $debounce = null;

    protected mixed $prefix = null;

    protected mixed $suffix = null;

    protected bool $isSaved = true;

    protected ?string $minValue = null;

    protected ?string $maxValue = null;

    /**
     * @var array<int, mixed>
     */
    protected array $rules = [];

    /**
     * Custom validation messages keyed by rule name (e.g. `['required' => '...']`).
     *
     * @var array<string, string>
     */
    protected array $validationMessages = [];

    protected ?string $validationAttribute = null;

    /**
     * @var array<string, string>
     */
    protected array $extraAttributes = [];

    /**
     * @var array<string, string>
     */
    protected array $extraInputAttributes = [];

    /**
     * @var array<string, string>
     */
    protected array $extraFieldWrapperAttributes = [];

    protected ?Closure $afterStateHydrated = null;

    protected ?Closure $afterStateUpdated = null;

    protected ?Closure $dehydrateStateUsing = null;

    /**
     * Client-side conditional rules keyed by effect — each maps to a
     * {@see FieldCondition} comparing ANOTHER field's live value. Serialized to
     * the browser (so KinetixForm shows/hides/disables/requires live) and
     * mirrored into server-side validation (so the client is never the only
     * guard).
     *
     * @var array{visible?: FieldCondition, hidden?: FieldCondition, required?: FieldCondition, disabled?: FieldCondition}
     */
    protected array $conditions = [];

    /**
     * Show this field only while another field's value satisfies the condition
     * (hidden otherwise). Client-side and live; the server excludes the field
     * from validation/state when the condition fails, so a conditionally-hidden
     * value is never required and never persisted.
     *
     *     TextInput::make('company_name')->visibleWhen('type', 'company');
     *     TextInput::make('vat')->visibleWhen('country', ['ES', 'FR'], 'in');
     */
    public function visibleWhen(string $field, mixed $value = true, string $operator = FieldCondition::EQUALS): static
    {
        $this->conditions['visible'] = new FieldCondition($field, $operator, $value);

        return $this;
    }

    /**
     * Hide this field while another field's value satisfies the condition
     * (the inverse of {@see visibleWhen()}).
     */
    public function hiddenWhen(string $field, mixed $value = true, string $operator = FieldCondition::EQUALS): static
    {
        $this->conditions['hidden'] = new FieldCondition($field, $operator, $value);

        return $this;
    }

    /**
     * Require this field only while another field's value satisfies the
     * condition. Enforced on the client (live `required`) and the server
     * (Laravel `required_if`/conditional rule).
     *
     *     TextInput::make('reason')->requiredWhen('status', 'rejected');
     */
    public function requiredWhen(string $field, mixed $value = true, string $operator = FieldCondition::EQUALS): static
    {
        $this->conditions['required'] = new FieldCondition($field, $operator, $value);

        return $this;
    }

    /**
     * Disable this field while another field's value satisfies the condition.
     * Purely a UI affordance (a disabled input still round-trips its current
     * value); pair with {@see visibleWhen()} to also drop it from the payload.
     */
    public function disabledWhen(string $field, mixed $value = true, string $operator = FieldCondition::EQUALS): static
    {
        $this->conditions['disabled'] = new FieldCondition($field, $operator, $value);

        return $this;
    }

    /**
     * The serialized conditions for the client, or null when none are set.
     *
     * @return array<string, array{field: string, operator: string, value: mixed}>|null
     */
    public function getConditionsData(): ?array
    {
        if ($this->conditions === []) {
            return null;
        }

        return array_map(static fn (FieldCondition $c): array => $c->toArray(), $this->conditions);
    }

    /**
     * Whether this field is conditionally EXCLUDED for a given data set — its
     * `visibleWhen` fails or its `hiddenWhen` holds. A excluded field is dropped
     * from server-side rules and state, exactly like a statically hidden one.
     *
     * @param array<string, mixed> $data
     */
    public function isConditionallyExcluded(array $data): bool
    {
        if (isset($this->conditions['visible']) && ! $this->conditions['visible']->passes($data)) {
            return true;
        }

        if (isset($this->conditions['hidden']) && $this->conditions['hidden']->passes($data)) {
            return true;
        }

        return false;
    }

    /**
     * Whether this field is conditionally required for a given data set.
     *
     * @param array<string, mixed> $data
     */
    public function isConditionallyRequired(array $data): bool
    {
        return isset($this->conditions['required']) && $this->conditions['required']->passes($data);
    }

    public function afterStateHydrated(Closure $callback): static
    {
        $this->afterStateHydrated = $callback;

        return $this;
    }

    /**
     * @deprecated NOT YET WIRED. Reserved for the planned server-driven
     * reactivity loop (`$get`/`$set` recomputing the schema on change). Today
     * the callback is stored but never invoked — there is no round-trip that
     * would call it — so setting it has no effect. Kept as a no-op rather than
     * removed so code written against it keeps working once reactivity lands;
     * do not rely on it firing until then. For hydration-time transforms use
     * {@see afterStateHydrated()}, which IS wired.
     */
    public function afterStateUpdated(Closure $callback): static
    {
        $this->afterStateUpdated = $callback;

        return $this;
    }

    public function dehydrateStateUsing(Closure $callback): static
    {
        $this->dehydrateStateUsing = $callback;

        return $this;
    }

    public function hydrate(mixed $value, ?Model $record = null): mixed
    {
        if ($this->afterStateHydrated !== null) {
            return ($this->afterStateHydrated)($value, $this, $record);
        }

        return $value;
    }

    public function dehydrate(mixed $value, ?Model $record = null): mixed
    {
        if ($this->dehydrateStateUsing !== null) {
            return ($this->dehydrateStateUsing)($value, $this, $record);
        }

        return $value;
    }

    public function __construct(string $name)
    {
        $this->name = $name;
        // Generate human-friendly label from column name
        $this->label = (string) str(str_replace('.', ' ', $name))->headline();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(mixed $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->defaultValue = $value;

        return $this;
    }

    public function disabled(mixed $condition = true): static
    {
        $this->isDisabled = $condition;

        return $this;
    }

    public function placeholder(mixed $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Mark the field "live". The `isLive`/`debounce` flags are serialized to
     * the client, but the server-driven reactivity loop that would act on them
     * (recomputing the schema, firing {@see afterStateUpdated()}) is NOT wired
     * yet, so today this only records intent. Safe to call — it won't error —
     * but don't expect dependent-field behaviour until reactivity lands.
     */
    public function live(bool $onBlur = false, ?int $debounce = null): static
    {
        $this->isLive = true;
        if ($onBlur) {
            $this->debounce = -1; // -1 represents onBlur
        } elseif ($debounce !== null) {
            $this->debounce = $debounce;
        }

        return $this;
    }

    public function prefix(mixed $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(mixed $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * @param array<int, mixed>|string|Closure $rules
     */
    public function rules(mixed $rules): static
    {
        $rules       = is_array($rules) ? $rules : [$rules];
        $this->rules = array_merge($this->rules, $rules);

        return $this;
    }

    /**
     * Helper text rendered under the field (the `{name}-help` element) and
     * referenced by the control's `aria-describedby` alongside any error.
     */
    protected string|Closure|null $helperText = null;

    public function helperText(string|Closure $text): static
    {
        $this->helperText = $text;

        return $this;
    }

    public function required(mixed $condition = true): static
    {
        if ($condition instanceof Closure) {
            $this->rules[] = function (?Model $record = null) use ($condition) {
                return $condition($record) ? 'required' : null;
            };
        } elseif ($condition) {
            $this->rules[] = 'required';
        }

        return $this;
    }

    /**
     * Override the validation messages for this field. Keys are rule names
     * (`required`, `email`, `max`…); they are namespaced to this field on the
     * way into the validator so they only apply here.
     *
     * @param array<string, string> $messages
     */
    public function validationMessages(array $messages): static
    {
        $this->validationMessages = array_merge($this->validationMessages, $messages);

        return $this;
    }

    /**
     * Human-friendly name used inside validation messages (`:attribute`).
     * Defaults to the field label when omitted.
     */
    public function validationAttribute(string $attribute): static
    {
        $this->validationAttribute = $attribute;

        return $this;
    }

    public function maxLength(int $length): static
    {
        $this->rules[] = "max:{$length}";

        return $this;
    }

    public function minLength(int $length): static
    {
        $this->rules[] = "min:{$length}";

        return $this;
    }

    public function numeric(): static
    {
        $this->rules[] = 'numeric';

        return $this;
    }

    public function email(): static
    {
        $this->rules[] = 'email';

        return $this;
    }

    public function url(): static
    {
        $this->rules[] = 'url';

        return $this;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function extraAttributes(array $attributes): static
    {
        $this->extraAttributes = $attributes;

        return $this;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function extraInputAttributes(array $attributes): static
    {
        $this->extraInputAttributes = $attributes;

        return $this;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function extraFieldWrapperAttributes(array $attributes): static
    {
        $this->extraFieldWrapperAttributes = $attributes;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The field's resolved validation rules.
     *
     * A field that isn't `required` but carries rules is also `nullable`: an
     * emptied input reaches the validator as null (`ConvertEmptyStringsToNull`),
     * and without it `email`, `url`, `numeric`, `min:`… would reject the very
     * emptiness that makes the field optional. Presence rules (`required_if`,
     * `accepted`, `present`…) are implicit, so they still run alongside it.
     *
     * Rule objects (`Password::min(8)`, `Rule::unique()`, a custom
     * `ValidationRule`) pass through untouched — the validator takes them as-is.
     *
     * @return array<int, mixed>
     */
    public function getRules(?Model $record = null): array
    {
        $resolvedRules = [];
        foreach ($this->rules as $rule) {
            $resolved = $rule instanceof Closure ? $rule($record) : $rule;

            if ($resolved === null) {
                continue;
            }

            $resolvedRules[] = is_object($resolved) ? $resolved : (string) $resolved;
        }

        if (
            $resolvedRules !== []
            && ! in_array('required', $resolvedRules, true)
            && ! in_array('nullable', $resolvedRules, true)
        ) {
            array_unshift($resolvedRules, 'nullable');
        }

        return $resolvedRules;
    }

    /**
     * Validation messages namespaced to this field (`{name}.{rule}` => message),
     * ready to merge into a Laravel validator's message bag.
     *
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        $messages = [];
        foreach ($this->validationMessages as $rule => $message) {
            $messages["{$this->name}.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * The `:attribute` replacement for this field — the explicit override,
     * otherwise the (string) label. Returns null when neither is available.
     */
    public function getValidationAttribute(?Model $record = null): ?string
    {
        if ($this->validationAttribute !== null) {
            return $this->validationAttribute;
        }

        $label = $this->label instanceof Closure ? ($this->label)($record) : $this->label;

        return $label !== null ? (string) $label : null;
    }

    /**
     * Earliest selectable value (native `min`), e.g. 'Y-m-d' / 'Y-m' / 'Y'.
     */
    public function minValue(string $value): static
    {
        $this->minValue = $value;

        return $this;
    }

    /**
     * Latest selectable value (native `max`).
     */
    public function maxValue(string $value): static
    {
        $this->maxValue = $value;

        return $this;
    }

    public function getDefaultValue(?Model $record = null): mixed
    {
        if ($this->defaultValue instanceof Closure) {
            return ($this->defaultValue)($record);
        }

        return $this->defaultValue;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $label       = $this->label instanceof Closure ? ($this->label)($record) : $this->label;
        $placeholder = $this->placeholder instanceof Closure ? ($this->placeholder)($record) : $this->placeholder;
        $prefix      = $this->prefix instanceof Closure ? ($this->prefix)($record) : $this->prefix;
        $suffix      = $this->suffix instanceof Closure ? ($this->suffix)($record) : $this->suffix;

        $isDisabled = $this->isDisabled;
        if ($isDisabled instanceof Closure) {
            $isDisabled = (bool) $this->evaluate($isDisabled, $record);
        }

        $helperText = $this->helperText instanceof Closure ? ($this->helperText)($record) : $this->helperText;

        return new FormFieldData(
            type: $this->getType(),
            name: $this->name,
            label: $label            !== null ? (string) $label : null,
            description: $helperText !== null ? (string) $helperText : null,
            columnSpan: $this->columnSpan,
            placeholder: $placeholder !== null ? (string) $placeholder : null,
            defaultValue: $this->getDefaultValue($record),
            isDisabled: (bool) $isDisabled,
            isLive: $this->isLive,
            debounce: $this->debounce,
            prefix: $prefix !== null ? (string) $prefix : null,
            suffix: $suffix !== null ? (string) $suffix : null,
            options: $this->getFieldOptions($record),
            extraAttributes: $this->extraAttributes ?: null,
            extraInputAttributes: $this->extraInputAttributes ?: null,
            extraFieldWrapperAttributes: $this->extraFieldWrapperAttributes ?: null,
            useCalendar: $this->dateConfig()['useCalendar'],
            dateLocale: $this->dateConfig()['locale'],
            minuteStep: $this->dateConfig()['minuteStep'],
            hour12: $this->dateConfig()['hour12'],
            confirm: (bool) ($this->dateConfig()['confirm'] ?? false),
            showToday: (bool) ($this->dateConfig()['showToday'] ?? false),
            closeOnSelect: (bool) ($this->dateConfig()['closeOnSelect'] ?? true),
            timezone: $this->dateConfig()['timezone'] ?? null,
            isRequired: in_array('required', $this->getRules($record), true),
            minValue: $this->minValue,
            maxValue: $this->maxValue,
            numberOfMonths: $this->rangeConfig()['numberOfMonths'],
            weekdayFormat: $this->rangeConfig()['weekdayFormat'],
            fixedWeeks: $this->rangeConfig()['fixedWeeks'],
            weekStartsOn: $this->rangeConfig()['weekStartsOn'] ?? null,
            conditions: $this->getConditionsData(),
        );
    }

    /**
     * Date/DateTime picker configuration. Overridden by DatePicker/DateTimePicker/
     * TimePicker, which add the optional behavior keys.
     *
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool, confirm?: bool, showToday?: bool, closeOnSelect?: bool, timezone?: string}
     */
    protected function dateConfig(): array
    {
        return ['useCalendar' => false, 'locale' => null, 'minuteStep' => 5, 'hour12' => false];
    }

    /**
     * Range-calendar configuration. Overridden by DateRangePicker / WeekPicker.
     *
     * @return array{numberOfMonths: int, weekdayFormat: ?string, fixedWeeks: bool, weekStartsOn: ?int}
     */
    protected function rangeConfig(): array
    {
        return ['numberOfMonths' => 1, 'weekdayFormat' => null, 'fixedWeeks' => false, 'weekStartsOn' => null];
    }

    /**
     * @return array<string, string>|null
     */
    protected function getFieldOptions(?Model $record = null): ?array
    {
        return null;
    }

    public function saved(bool $condition = true): static
    {
        $this->isSaved = $condition;

        return $this;
    }

    public function isSaved(): bool
    {
        return $this->isSaved;
    }

    /**
     * Call a user closure injecting the arguments it asks for BY TYPE or NAME,
     * Filament-style: a parameter typed {@see Get} or named `$get` receives the
     * state reader, `Set`/`$set` the state writer, `Model`/`$record` the record.
     *
     * Backward compatible: a legacy single-parameter closure (`fn ($record) =>
     * …`, untyped) still receives the record as its first argument, so every
     * existing `visible`/`disabled`/`options`/`default` closure keeps working
     * unchanged. Returns the closure's result.
     */
    protected function evaluate(
        Closure $callback,
        ?Model $record = null,
        ?Get $get = null,
        ?Set $set = null,
    ): mixed {
        $reflection = new \ReflectionFunction($callback);
        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            return $callback();
        }

        $args = [];

        foreach ($parameters as $index => $parameter) {
            $type     = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $name     = $parameter->getName();

            $args[] = match (true) {
                $typeName === Get::class   || $name === 'get'    => $get ?? new Get($this->reactiveState ?? []),
                $typeName === Set::class   || $name === 'set'    => $set,
                $typeName === Model::class || $name === 'record' => $record,
                $typeName === 'array' && $name === 'state'       => $this->reactiveState ?? [],
                // First untyped/positional parameter of a legacy closure: the
                // record, preserving `fn ($record) => …` and `fn ($value) => …`
                // (the latter for formatters that are evaluated elsewhere).
                $index === 0 => $record,
                default      => null,
            };
        }

        return $callback(...$args);
    }

    /**
     * The form's working state during a server-side recompute, so a reactive
     * closure's `Get` without an explicit instance still reads live values.
     * Null outside a recompute (the static serialization pass).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $reactiveState = null;

    /**
     * Seed the state a recompute evaluates this field's reactive closures
     * against. Returns $this for chaining inside the Form recompute loop.
     *
     * @param array<string, mixed> $state
     */
    public function withReactiveState(array $state): static
    {
        $this->reactiveState = $state;

        return $this;
    }

    /**
     * Whether this field opts into the reactive loop (`->live()`), so the form
     * knows to re-serialize when it changes.
     */
    public function isLive(): bool
    {
        return $this->isLive;
    }

    /**
     * Fire this field's `afterStateUpdated()` during a recompute, giving it a
     * {@see Get} over the working state and a {@see Set} that writes derived
     * values back into both the state and the `$changes` delta the response
     * ships. No-op when the field declared no callback.
     *
     * @param array<string, mixed> $state   Working state (by reference).
     * @param array<string, mixed> $changes Accumulated deltas (by reference).
     */
    public function runAfterStateUpdated(array &$state, array &$changes, ?Model $record = null): void
    {
        if ($this->afterStateUpdated === null) {
            return;
        }

        $get = new Get($state);
        $set = new Set($state, $changes);

        $this->evaluate($this->afterStateUpdated, $record, $get, $set);
    }
}
