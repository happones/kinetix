<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
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

    public function afterStateHydrated(Closure $callback): static
    {
        $this->afterStateHydrated = $callback;

        return $this;
    }

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
     * @return array<int, string>
     */
    public function getRules(?Model $record = null): array
    {
        $resolvedRules = [];
        foreach ($this->rules as $rule) {
            if ($rule instanceof Closure) {
                $res = $rule($record);
                if ($res !== null) {
                    $resolvedRules[] = (string) $res;
                }
            } else {
                $resolvedRules[] = (string) $rule;
            }
        }

        return $resolvedRules;
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
            $isDisabled = (bool) $isDisabled($record);
        }

        return new FormFieldData(
            type: $this->getType(),
            name: $this->name,
            label: $label !== null ? (string) $label : null,
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
            isRequired: in_array('required', $this->getRules($record), true),
            minValue: $this->minValue,
            maxValue: $this->maxValue,
            numberOfMonths: $this->rangeConfig()['numberOfMonths'],
            weekdayFormat: $this->rangeConfig()['weekdayFormat'],
            fixedWeeks: $this->rangeConfig()['fixedWeeks'],
            weekStartsOn: $this->rangeConfig()['weekStartsOn'] ?? null,
        );
    }

    /**
     * Date/DateTime picker configuration. Overridden by DatePicker/DateTimePicker.
     *
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool}
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
}
