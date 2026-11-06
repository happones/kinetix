<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Data\FormData;
use Happones\Kinetix\Data\FormFieldData;
use Happones\Kinetix\Forms\Components\Component;
use Happones\Kinetix\Forms\Components\Field;
use Happones\Kinetix\Forms\Components\Repeater;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\Contracts\ResolvesRelationships;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\RequiredIf;
use JsonSerializable;
use ReflectionClass;
use Stringable;

class Form implements Arrayable, JsonSerializable
{
    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    protected ?Model $record = null;

    /**
     * Whether {@see fill()} ran. A form rendered without it (a create form, an
     * action's modal) still opens with its fields' defaults.
     */
    protected bool $isFilled = false;

    protected ?string $model = null;

    protected string $operation = 'create';

    /**
     * Form-level validation message overrides (highest precedence).
     *
     * @var array<string, string>
     */
    protected array $messages = [];

    /**
     * Form-level `:attribute` overrides (highest precedence).
     *
     * @var array<string, string>
     */
    protected array $validationAttributes = [];

    protected bool $precognitive = false;

    protected ?string $validationUrl = null;

    protected string $validationMethod = 'post';

    /**
     * Explicit reconstruction source for the reactivity loop, when the form
     * isn't a `Form` subclass that can rebuild itself: the resource whose
     * `form()` produced this schema. Set by Kinetix (record modals / resource
     * pages); a hand-built inline `Form::make()` with no source stays
     * non-reactive (its closures can't be rebuilt server-side).
     */
    protected ?string $reactiveResource = null;

    /**
     * The value a clearable write-only field submits to remove its stored
     * value ({@see FormFieldData::$isClearable}). Honoured only for those
     * fields; on any other field it means "unchanged" and is never stored.
     */
    public const CLEAR_SENTINEL = '__kinetix_clear__';

    /**
     * Fields whose value never travels to the browser ({@see writeOnly()}).
     *
     * @var list<string>
     */
    protected array $writeOnly = [];

    /**
     * The write-only fields {@see fill()} found a stored value for.
     *
     * @var array<string, true>
     */
    protected array $storedWriteOnly = [];

    /**
     * Write-only fields whose value is inherited from elsewhere (a team
     * settings page over a global secret): blank keeps inheriting it, so they
     * are not required — but the value isn't this form's own, so they are not
     * flagged as withheld either.
     *
     * @var array<string, true>
     */
    protected array $inheritedWriteOnly = [];

    /**
     * Opt a resource-built or inline form into the reactivity loop by naming
     * the class that can rebuild it server-side. A `Form` SUBCLASS needs no
     * call — it rebuilds via `new static($record)`.
     *
     * @param class-string<\Happones\Kinetix\Resources\Resource> $resource
     */
    public function reactiveVia(string $resource): static
    {
        $this->reactiveResource = $resource;

        return $this;
    }

    public function __construct(?Model $record = null)
    {
        if ($record !== null) {
            $this->record    = $record;
            $this->model     = get_class($record);
            $this->operation = $record->exists ? 'edit' : 'create';
        }

        $this->schema = $this->buildSchema();
    }

    protected function buildSchema(): array
    {
        return [];
    }

    public static function render(?Model $record = null, mixed $fillData = null): array
    {
        $form = static::make($record);
        if ($fillData !== null) {
            $form->fill($fillData);
        } elseif ($record !== null) {
            $form->fill($record);
        }

        return $form->toArray();
    }

    public static function make(?Model $record = null): static
    {
        return new static($record);
    }

    /**
     * A form whose fields are inferred from a FormRequest's rules (see
     * {@see FormRequestFields}), filled from $record when given.
     *
     * @param class-string<FormRequest> $request
     */
    public static function fromRequest(string $request, ?Model $record = null): static
    {
        $form = static::make($record)->schema(FormRequestFields::components($request));

        return $record !== null ? $form->fill($record) : $form;
    }

    /**
     * Set the form schema.
     *
     * @param array<int, Component> $components
     */
    public function schema(array $components): static
    {
        $this->schema = $components;

        return $this;
    }

    public function operation(string $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Make fields write-only: whatever value they have is never sent to the
     * browser — they render empty, flagged `isWithheld` when a value is
     * stored — and a submit that leaves them blank (or unchanged) keeps the
     * stored value instead of clearing it. For secrets: API keys, passwords,
     * tokens. Call it before {@see fill()}, which records which of them hold
     * a value.
     *
     * @param list<string> $names
     */
    public function writeOnly(array $names): static
    {
        $this->writeOnly = array_values(array_unique([...$this->writeOnly, ...$names]));

        return $this;
    }

    /**
     * Mark write-only fields whose value is inherited from another scope: a
     * blank submit keeps inheriting it (not validated, not written), while
     * the field still renders as holding no value of its own.
     *
     * @internal used by settings pages
     *
     * @param list<string> $names
     */
    public function inheritingWriteOnly(array $names): static
    {
        foreach ($names as $name) {
            $this->inheritedWriteOnly[$name] = true;
        }

        return $this;
    }

    /**
     * Fill the form data from array or Model.
     */
    public function fill(mixed $data = []): static
    {
        $this->isFilled = true;

        if ($data instanceof Model) {
            $this->record    = $data;
            $this->model     = get_class($data);
            $this->operation = $data->exists ? 'edit' : 'create';

            $fields        = $this->getFields();
            $extractedData = [];
            foreach ($fields as $name => $field) {
                // A hidden attribute (`password`, `remember_token`, a 2FA
                // secret) never travels to the browser: data_get() reads past
                // $hidden, so an edit form used to ship the password hash. On
                // an edit it renders empty — not as the field's default, which
                // saving untouched would then write over the stored value.
                $hidden = $this->isHiddenAttribute($data, $name);
                if ($hidden && $data->exists) {
                    $value = null;
                } else {
                    $value = ($hidden ? null : data_get($data, $name)) ?? $field->getDefaultValue($data);
                }

                $value                = $field->hydrate($value, $data);
                $extractedData[$name] = $value;
            }
            $this->data = $extractedData;
        } else {
            $this->data = (array) $data;

            $fields = $this->getFields();
            foreach ($fields as $name => $field) {
                if (! array_key_exists($name, $this->data)) {
                    $val               = $field->getDefaultValue($this->record);
                    $this->data[$name] = $field->hydrate($val, $this->record);
                }
            }
        }

        $this->withholdWriteOnly($data);

        return $this;
    }

    /**
     * Record which write-only fields hold a value in `$source`, then blank
     * them so the value never leaves the server.
     */
    protected function withholdWriteOnly(mixed $source): void
    {
        if ($this->writeOnly === []) {
            return;
        }

        $fields = $this->getFields();

        foreach ($this->writeOnly as $name) {
            $stored = $source instanceof Model
                ? data_get($source, $name)
                : (is_array($source) ? ($source[$name] ?? null) : null);

            if (blank($stored)) {
                unset($this->storedWriteOnly[$name]);
            } else {
                $this->storedWriteOnly[$name] = true;
            }

            $this->data[$name] = isset($fields[$name]) ? $fields[$name]->hydrate(null, $this->record) : null;
        }
    }

    /**
     * Override validation messages at the form level (highest precedence).
     * Keys are the standard Laravel dotted form (`email.required`, `email.email`).
     *
     * @param array<string, string> $messages
     */
    public function messages(array $messages): static
    {
        $this->messages = array_merge($this->messages, $messages);

        return $this;
    }

    /**
     * Override `:attribute` names at the form level (highest precedence).
     *
     * @param array<string, string> $attributes
     */
    public function validationAttributes(array $attributes): static
    {
        $this->validationAttributes = array_merge($this->validationAttributes, $attributes);

        return $this;
    }

    /**
     * Opt this form into live, server-authoritative validation via Laravel
     * Precognition. The client validates fields as the user edits them by
     * hitting `$validationUrl` (defaults to the submit endpoint, set on the
     * client) with a `Precognition` header — reusing these exact rules.
     */
    public function precognitive(bool $condition = true): static
    {
        $this->precognitive = $condition;

        return $this;
    }

    /**
     * Point Precognition validation at a specific endpoint. Optional — when
     * omitted the client reuses the form's submit URL.
     */
    public function validationUrl(string $url, string $method = 'post'): static
    {
        $this->validationUrl    = $url;
        $this->validationMethod = strtolower($method);
        $this->precognitive     = true;

        return $this;
    }

    /**
     * Get all validation rules.
     *
     * @param  array<string, mixed>             $data The submitted values, so conditional
     *                                                rules (visibleWhen/requiredWhen) can be
     *                                                evaluated server-side.
     * @return array<string, array<int, mixed>>
     */
    public function getValidationRules(array $data = []): array
    {
        $rules  = [];
        $fields = $this->getFields();
        foreach ($fields as $name => $field) {
            if ($field->isHidden($this->operation, $this->record)) {
                continue;
            }

            // A conditionally-hidden field is excluded from validation
            // entirely — its rules (incl. a static `required`) must not block a
            // submit where the field isn't even shown.
            if ($field->isConditionallyExcluded($data)) {
                continue;
            }

            // A write-only value the user chose to remove: no rule runs on
            // the sentinel.
            if ($this->clears($field, $name, $data)) {
                continue;
            }

            // A hidden attribute left as it rendered keeps its stored value
            // (see keepsStoredValue()), so there is nothing to validate.
            if ($this->keepsStoredValue($field, $name, $data)) {
                continue;
            }

            // A disabled field is never written, so nothing to validate
            // either — unless it opted into being saved.
            if ($this->ignoresDisabled($field, $data)) {
                continue;
            }

            $fieldRules = $field->getRules($this->record);

            // A conditionally-required field gains `required` server-side when
            // its condition holds (and loses the client-only `nullable` that
            // getRules() prepends for an optional field).
            if ($field->isConditionallyRequired($data) && ! in_array('required', $fieldRules, true)) {
                $fieldRules = array_values(array_filter($fieldRules, static fn ($r): bool => $r !== 'nullable'));
                array_unshift($fieldRules, 'required');
            }

            $rules[$name] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Aggregate validation messages: field-level (`->validationMessages()`)
     * first, then form-level overrides which win.
     *
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        $messages = [];
        foreach ($this->getFields() as $field) {
            if (! $field->isHidden($this->operation, $this->record)) {
                $messages = array_merge($messages, $field->getValidationMessages());
            }
        }

        return array_merge($messages, $this->messages);
    }

    /**
     * Aggregate `:attribute` names: each visible field defaults to its label,
     * then form-level overrides win. Empty entries are dropped so Laravel falls
     * back to its own humanised name.
     *
     * @return array<string, string>
     */
    public function getValidationAttributes(): array
    {
        $attributes = [];
        foreach ($this->getFields() as $name => $field) {
            if ($field->isHidden($this->operation, $this->record)) {
                continue;
            }

            $attribute = $field->getValidationAttribute($this->record);
            if ($attribute !== null && $attribute !== '') {
                $attributes[$name] = $attribute;
            }
        }

        return array_merge($attributes, $this->validationAttributes);
    }

    /**
     * The validation rules for a submission: conditional rules
     * (visibleWhen/requiredWhen/…) read the form's data merged with the
     * submitted input — the same values {@see getState()} decides persistence
     * from, so a field the user saw is validated and a hidden one isn't.
     *
     * @param  array<string, mixed>             $inputData
     * @return array<string, array<int, mixed>>
     */
    public function getValidationRulesForInput(array $inputData): array
    {
        return $this->getValidationRules(array_merge($this->data, $inputData));
    }

    /**
     * Build a Laravel validator seeded with this form's rules, messages, and
     * attributes. Shared by `validate()` and the FormRequest bridge so every
     * validation path (fluent, FormRequest, Precognition) stays identical.
     *
     * @param array<string, mixed> $inputData
     */
    public function makeValidator(array $inputData = []): \Illuminate\Validation\Validator
    {
        $data = array_merge($this->data, $inputData);

        return Validator::make(
            $data,
            $this->getValidationRulesForInput($inputData),
            $this->getValidationMessages(),
            $this->getValidationAttributes(),
        );
    }

    /**
     * Validate the form input, throwing a ValidationException on failure.
     *
     * @return array<string, mixed>
     */
    public function validate(array $inputData = []): array
    {
        return $this->makeValidator($inputData)->validate();
    }

    /**
     * Dehydrate the form and return the processed states.
     *
     * @return array<string, mixed>
     */
    public function getState(array $inputData = []): array
    {
        $data   = array_merge($this->data, $inputData);
        $state  = [];
        $fields = $this->getFields();

        foreach ($fields as $name => $field) {
            if ($field->isHidden($this->operation, $this->record) || ! $field->isSaved()) {
                continue;
            }

            // A conditionally-hidden field is not persisted — a smuggled value
            // for a field the form never showed never reaches the model.
            if ($field->isConditionallyExcluded($data)) {
                continue;
            }

            // Removing a write-only value: the field's empty state.
            if ($this->clears($field, $name, $data)) {
                $state[$name] = $field->dehydrate(null, $this->record);

                continue;
            }

            if ($this->keepsStoredValue($field, $name, $data)) {
                continue;
            }

            // A disabled field takes no input: a record keeps what it has;
            // otherwise the value the form was filled with (a settings page's
            // current value), else the default. A submitted value is ignored.
            if ($this->ignoresDisabled($field, $data)) {
                if ($this->record !== null && $this->record->exists) {
                    continue;
                }

                $state[$name] = $field->dehydrate($this->data[$name] ?? $field->getDefaultValue($this->record), $this->record);

                continue;
            }

            if ($this->isBlankWriteOnly($field, $name, $data)) {
                continue;
            }

            $value = $data[$name] ?? $field->getDefaultValue($this->record);

            // A repeater's rows: their disabled sub-fields come from the
            // stored row each row's key names, else their default.
            if ($field instanceof Repeater && is_array($value)) {
                $value = $field->lockRows($value, $this->storedRows($field, $name), $this->record, static::class);
            }

            $state[$name] = $field->dehydrate($value, $this->record);
        }

        return $state;
    }

    /**
     * The rows a repeater rendered: the form's own data when it was filled,
     * else the record's stored value — the source {@see getState()} judges
     * top-level disabled fields from.
     *
     * @return array<array-key, mixed>
     */
    private function storedRows(Repeater $field, string $name): array
    {
        if ($this->isFilled) {
            $rows = $this->data[$name] ?? [];
        } elseif ($this->record !== null && $this->record->exists) {
            $rows = $field->hydrate(data_get($this->record, $name), $this->record);
        } else {
            $rows = [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * `$data` with every repeater's rows keyed ({@see Repeater::keyRows()}),
     * for the copy that travels to the browser.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function keyRepeaterRows(array $data): array
    {
        foreach ($this->getFields() as $name => $field) {
            if ($field instanceof Repeater && is_array($data[$name] ?? null)) {
                $data[$name] = $field->keyRows($data[$name], $this->record, static::class);
            }
        }

        return $data;
    }

    /**
     * Whether `$name` is an attribute the form withholds ({@see withholds()}):
     * one its model keeps out of serialization, or a confidential one. A
     * dotted name (`owner.password`) is judged on the related model that
     * holds it, and through every relation on the way.
     */
    protected function isHiddenAttribute(Model $record, string $name): bool
    {
        $segments  = explode('.', $name);
        $attribute = array_pop($segments);
        $owner     = $record;

        foreach ($segments as $segment) {
            if (self::withholds($owner, $segment)) {
                return true;
            }

            $related = data_get($owner, $segment);

            // A JSON column's key (`settings.theme`): the column decided.
            if (! $related instanceof Model) {
                return false;
            }

            $owner = $related;
        }

        return self::withholds($owner, $attribute);
    }

    /**
     * Whether an edit form must not ship `$attribute`, and must keep its
     * stored value when it comes back blank or unchanged: it is listed in
     * `$hidden`, left out of a `$visible` whitelist, or confidential
     * ({@see ConfidentialCast}). A confidential value is withheld even while
     * unlocked: its mask is not the value, and saving it back would replace
     * the real one.
     */
    private static function withholds(Model $model, string $attribute): bool
    {
        if (ConfidentialCast::appliesTo($model, $attribute)) {
            return true;
        }

        $visible = $model->getVisible();

        return in_array($attribute, $model->getHidden(), true)
            || ($visible !== [] && ! in_array($attribute, $visible, true));
    }

    /**
     * A withheld attribute (hidden or confidential) of an existing record
     * keeps the value it has unless
     * the user entered a new one: the form rendered it empty, so coming back
     * blank, off, or as the field's empty state means "unchanged", not "clear
     * it". (Before, the hash itself made the round trip.)
     *
     * @param array<string, mixed> $data
     */
    protected function keepsStoredValue(Field $field, string $name, array $data): bool
    {
        if (in_array($name, $this->writeOnly, true)) {
            $keeps = $this->hasStoredWriteOnly($name) || isset($this->inheritedWriteOnly[$name]);

            return $keeps && $this->isUnchangedWithheld($field, $name, $data);
        }

        if ($this->record === null || ! $this->record->exists || ! $this->isHiddenAttribute($this->record, $name)) {
            return false;
        }

        return $this->isUnchangedWithheld($field, $name, $data);
    }

    /**
     * Whether a withheld field came back as it rendered: blank, off, or the
     * field's empty state.
     *
     * @param array<string, mixed> $data
     */
    private function isUnchangedWithheld(Field $field, string $name, array $data): bool
    {
        $submitted = $data[$name] ?? null;

        // The clear sentinel on a field that may not be cleared: unchanged,
        // so it is never stored.
        return $submitted === self::CLEAR_SENTINEL
            || blank($submitted)
            || $submitted === false
            || $submitted == $field->hydrate(null, $this->record);
    }

    /**
     * A write-only field with nothing stored that came back blank: there is
     * nothing to keep and nothing to write.
     *
     * @param array<string, mixed> $data
     */
    private function isBlankWriteOnly(Field $field, string $name, array $data): bool
    {
        return in_array($name, $this->writeOnly, true) && $this->isUnchangedWithheld($field, $name, $data);
    }

    /**
     * Whether `$name` may be cleared from the form: a write-only field
     * holding its OWN stored value (not one inherited from another scope)
     * that is not required.
     *
     * @param array<string, mixed> $data
     */
    private function isClearable(Field $field, string $name, array $data = []): bool
    {
        if (! in_array($name, $this->writeOnly, true) || isset($this->inheritedWriteOnly[$name]) || ! $this->hasStoredWriteOnly($name)) {
            return false;
        }

        if (self::hasRequiredRule($field->getRules($this->record)) || $this->ignoresDisabled($field, $data)) {
            return false;
        }

        return ! $field->isConditionallyRequired($data);
    }

    /**
     * Whether any rule can require the field: `required`, any `required_*`
     * (`required_with`, `required_if`, …) in a rule string or a pipe-joined
     * one, or a rule object such as `Rule::requiredIf()`.
     *
     * @param array<int, mixed> $rules
     */
    private static function hasRequiredRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule instanceof RequiredIf) {
                return true;
            }

            if (is_object($rule)) {
                $rule = $rule instanceof Stringable ? (string) $rule : '';
            }

            if (! is_string($rule)) {
                continue;
            }

            foreach (explode('|', $rule) as $part) {
                if (str_starts_with(strtolower(trim(explode(':', $part, 2)[0])), 'required')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether this submit removes a write-only field's stored value: the
     * field is clearable and came back as {@see CLEAR_SENTINEL}.
     *
     * @param array<string, mixed> $data
     */
    private function clears(Field $field, string $name, array $data): bool
    {
        return ($data[$name] ?? null) === self::CLEAR_SENTINEL && $this->isClearable($field, $name, $data);
    }

    /**
     * Whether a write-only field has a stored value: what {@see fill()}
     * recorded, or — for a form never filled — the record's attribute.
     */
    private function hasStoredWriteOnly(string $name): bool
    {
        if (isset($this->storedWriteOnly[$name])) {
            return true;
        }

        return ! $this->isFilled && $this->record !== null && $this->record->exists && ! blank(data_get($this->record, $name));
    }

    /**
     * Whether a disabled field's submitted value must be ignored: it is
     * disabled for this submit and didn't opt into
     * {@see Field::savedWhenDisabled()}.
     *
     * @param array<string, mixed> $data
     */
    private function ignoresDisabled(Field $field, array $data): bool
    {
        return ! $field->isSavedWhenDisabled() && $field->isDisabledFor($data, $this->record);
    }

    /**
     * The names whose stored value the form holds back: write-only fields
     * with a value, and withheld model attributes (hidden or confidential)
     * whose raw stored value is set.
     *
     * @return array<string, true>
     */
    protected function withheldNames(): array
    {
        $names = [];

        foreach (array_keys($this->getFields()) as $name) {
            if (in_array($name, $this->writeOnly, true)) {
                if ($this->hasStoredWriteOnly($name)) {
                    $names[$name] = true;
                }

                continue;
            }

            if ($this->record !== null && $this->record->exists && $this->isHiddenAttribute($this->record, $name) && $this->storedRawValue($this->record, $name) !== null) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * The raw stored value behind `$name` (no casts: a confidential value is
     * its ciphertext), walking a dotted name through its relations.
     */
    private function storedRawValue(Model $record, string $name): mixed
    {
        $segments  = explode('.', $name);
        $attribute = array_pop($segments);
        $owner     = $record;

        foreach ($segments as $segment) {
            $related = data_get($owner, $segment);

            if (! $related instanceof Model) {
                return data_get($record, $name);
            }

            $owner = $related;
        }

        return $owner->getAttributes()[$attribute] ?? null;
    }

    /**
     * Flag the serialized fields whose stored value is held back. Descends
     * into layout components only: a repeater's own rows are not this form's
     * fields.
     *
     * @param array<int, mixed>   $components
     * @param array<string, true> $names
     * @param array<string, true> $clearable  the withheld names the user may clear
     */
    private static function flagWithheld(array $components, array $names, array $clearable = []): void
    {
        foreach ($components as $component) {
            if (! $component instanceof FormFieldData) {
                continue;
            }

            if ($component->name !== null) {
                $component->isWithheld  = isset($names[$component->name]);
                $component->isClearable = isset($clearable[$component->name]);

                continue;
            }

            if (is_array($component->schema)) {
                self::flagWithheld($component->schema, $names, $clearable);
            }
        }
    }

    /**
     * Get all fields recursively.
     *
     * @return array<string, Field>
     */
    public function getFields(): array
    {
        return self::fieldsIn($this->schema);
    }

    /**
     * The fields among `$components`, through their layout components (a
     * Repeater is a field: its sub-schema is not walked).
     *
     * @param  array<int, Component> $components
     * @return array<string, Field>
     */
    public static function fieldsIn(array $components): array
    {
        $fields = [];
        self::collectFields($components, $fields);

        return $fields;
    }

    /**
     * Extract fields recursively.
     *
     * @param array<int, Component> $components
     * @param array<string, Field>  $fields
     */
    protected static function collectFields(array $components, array &$fields): void
    {
        foreach ($components as $component) {
            if ($component instanceof Field) {
                $fields[$component->getName()] = $component;
            } elseif ($component instanceof Component) {
                $refClass = new ReflectionClass($component);
                if ($refClass->hasProperty('schema')) {
                    $prop = $refClass->getProperty('schema');
                    $prop->setAccessible(true);
                    self::collectFields($prop->getValue($component), $fields);
                }
            }
        }
    }

    /**
     * Re-serialize the schema against a partial, in-flight state — the heart of
     * the server-driven reactivity loop ($get/$set). Given the values the user
     * has entered so far, it:
     *
     *   1. seeds every field's reactive state, so their `options`/`visible`/
     *      `disabled` closures (which take a {@see Get}) resolve against the
     *      LIVE values rather than the record;
     *   2. fires `afterStateUpdated()` for the `live()` fields that CHANGED
     *      (`$changed`), letting them push derived values back through a
     *      {@see Set} (e.g. clearing a dependent select) — collected as
     *      `changes`. Firing every live field's hook on every keystroke reset
     *      cascades: picking a state re-ran the country's "clear the state"
     *      hook. With no `$changed` (a client from before 0.208) every live
     *      field's hook runs, as it used to;
     *   3. returns the freshly serialized schema plus those changes.
     *
     * It never runs anything the client sent: the schema and its closures come
     * from THIS form instance (rebuilt server-side from its class), so the
     * client only ever supplies plain values.
     *
     * @param  array<string, mixed>                                                           $data
     * @param  list<string>|null                                                              $changed the fields the user changed since the last recompute
     * @return array{schema: array<int, array<string, mixed>>, changes: array<string, mixed>}
     */
    public function recompute(array $data, ?array $changed = null): array
    {
        $state   = array_merge($this->data, $data);
        $changes = [];
        $fields  = $this->getFields();

        if ($this->model !== null) {
            foreach ($fields as $field) {
                if ($field instanceof ResolvesRelationships) {
                    $field->forModel($this->model);
                }
            }
        }

        // Fire afterStateUpdated for the live fields that changed, in the order
        // they changed, letting each push derived values into the shared
        // working state (visible to later reads).
        $hooks = $changed ?? array_keys($fields);

        foreach ($hooks as $name) {
            $field = $fields[$name] ?? null;

            if ($field === null || ! $field->isLive()) {
                continue;
            }

            $field->runAfterStateUpdated($state, $changes, $this->record);
        }

        // Repeater rows a hook set keep the `Repeater::ROW_KEY` they came in
        // with (the submit verifies it), so reordering, dropping or adding
        // rows never re-keys them by position; a row that arrives without a
        // key (one the hook built) is never given one: it is a new row.

        // Seed the (possibly $set-mutated) state into every field so the
        // re-serialization evaluates reactive closures against it.
        foreach ($fields as $field) {
            $field->withReactiveState($state);
        }

        $serializedSchema = [];
        foreach ($this->schema as $component) {
            if ($component instanceof Field) {
                $component->withReactiveState($state);
            }

            $componentData = $component->toData($this->operation, $this->record);
            if ($componentData !== null) {
                $serializedSchema[] = $componentData->toArray();
            }
        }

        return ['schema' => $serializedSchema, 'changes' => $changes];
    }

    /**
     * Convert to Spatie FormData.
     */
    public function toData(): FormData
    {
        // Never filled: open with the defaults — never with a bound record's
        // values, which an action's form (built on its row) doesn't collect.
        if (! $this->isFilled) {
            $this->fill([]);
        }

        // Hand each relationship-aware field the owning model, the way Table
        // does for its filters — without it, `relationship('author', 'name')`
        // has no model to resolve the relation against.
        if ($this->model !== null) {
            foreach ($this->getFields() as $field) {
                if ($field instanceof ResolvesRelationships) {
                    $field->forModel($this->model);
                }
            }
        }

        // Seed the form's values into every field, so a `Get` read by an
        // options/visible closure sees them on the FIRST render too — an edit
        // form's dependent select, or a create form re-rendered with the old
        // input after a failed submit, would otherwise start empty.
        foreach ($this->getFields() as $field) {
            $field->withReactiveState($this->data);
        }

        $serializedSchema = [];
        foreach ($this->schema as $component) {
            $componentData = $component->toData($this->operation, $this->record);
            if ($componentData !== null) {
                $serializedSchema[] = $componentData;
            }
        }

        $withheld = $this->withheldNames();

        if ($withheld !== []) {
            $fields    = $this->getFields();
            $clearable = [];

            foreach (array_keys($withheld) as $name) {
                if (isset($fields[$name]) && $this->isClearable($fields[$name], $name, $this->data)) {
                    $clearable[$name] = true;
                }
            }

            self::flagWithheld($serializedSchema, $withheld, $clearable);
        }

        return new FormData(
            schema: $serializedSchema,
            data: $this->keyRepeaterRows($this->data),
            rules: $this->clientRules(),
            operation: $this->operation,
            precognitive: $this->precognitive,
            validationUrl: $this->validationUrl,
            validationMethod: $this->validationMethod,
            recomputeDescriptor: $this->buildRecomputeDescriptor(),
        );
    }

    /**
     * Seal the descriptor the reactivity endpoint rebuilds this form from, or
     * null when the form isn't reactive. Reactive requires BOTH:
     *   - at least one `live()` field (nothing to react to otherwise), and
     *   - a reconstruction source: a `Form` SUBCLASS (rebuilt via
     *     `new static($record)`) or an explicit `reactiveVia(resource)`.
     *
     * An anonymous inline `Form::make()->schema([...])` has neither, so its
     * closures can't be re-run server-side and it stays non-reactive. The
     * descriptor carries only class references + the record id (never a
     * closure), bound to the user/team/expiry like every signed descriptor.
     */
    protected function buildRecomputeDescriptor(): ?string
    {
        $hasLive = false;

        foreach ($this->getFields() as $field) {
            if ($field->isLive()) {
                $hasLive = true;

                break;
            }
        }

        if (! $hasLive) {
            return null;
        }

        $isFormSubclass = static::class !== Form::class;

        if (! $isFormSubclass && $this->reactiveResource === null) {
            return null;
        }

        return SignedDescriptor::seal([
            'formClass' => $isFormSubclass ? static::class : null,
            'resource'  => $this->reactiveResource,
            'operation' => $this->operation,
            'model'     => $this->model,
            'recordId'  => $this->record?->getKey(),
        ], DescriptorAudience::FormRecompute);
    }

    /**
     * The validation rules as they travel to the client: strings only. A rule
     * object is reduced to its rule string when it has one (`Rule::in()`,
     * `Rule::unique()`) and left out otherwise, so a custom rule's public
     * properties never end up in the page props.
     *
     * @return array<string, array<int, string>>
     */
    protected function clientRules(): array
    {
        return array_map(
            static fn (array $rules): array => array_values(array_filter(array_map(
                static fn (mixed $rule): ?string => is_object($rule)
                    ? ($rule instanceof Stringable ? (string) $rule : null)
                    : (string) $rule,
                $rules,
            ), is_string(...))),
            $this->getValidationRules(),
        );
    }

    public function toArray(): array
    {
        return $this->toData()->toArray();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
