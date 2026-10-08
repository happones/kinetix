<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Data\FormData;
use Happones\Kinetix\Forms\Components\Component;
use Happones\Kinetix\Forms\Components\Field;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\Contracts\ResolvesRelationships;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
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
     * Fill the form data from array or Model.
     */
    public function fill(mixed $data = []): static
    {
        if ($data instanceof Model) {
            $this->record    = $data;
            $this->model     = get_class($data);
            $this->operation = $data->exists ? 'edit' : 'create';

            $fields        = $this->getFields();
            $extractedData = [];
            foreach ($fields as $name => $field) {
                // A hidden attribute (`password`, `remember_token`, a 2FA
                // secret) never travels to the browser: data_get() reads past
                // $hidden, so an edit form used to ship the password hash.
                $value = $this->isHiddenAttribute($data, $name) ? null : data_get($data, $name);
                if ($value === null) {
                    $value = $field->getDefaultValue($data);
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

        return $this;
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

            // A hidden attribute left blank keeps its stored value (see
            // keepsStoredValue()), so there is nothing to validate.
            if ($this->keepsStoredValue($name, $data)) {
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

            if ($this->keepsStoredValue($name, $data)) {
                continue;
            }

            $value        = $data[$name] ?? $field->getDefaultValue($this->record);
            $state[$name] = $field->dehydrate($value, $this->record);
        }

        return $state;
    }

    /**
     * Whether `$name` is an attribute the record hides from serialization.
     */
    protected function isHiddenAttribute(Model $record, string $name): bool
    {
        return in_array($name, $record->getHidden(), true);
    }

    /**
     * A hidden attribute of an existing record that comes back blank keeps the
     * value it has: the form never sent it, so blank means "unchanged", not
     * "clear it". (Before, the hash itself made the round trip.)
     *
     * @param array<string, mixed> $data
     */
    protected function keepsStoredValue(string $name, array $data): bool
    {
        return $this->record !== null
            && $this->record->exists
            && $this->isHiddenAttribute($this->record, $name)
            && blank($data[$name] ?? null);
    }

    /**
     * Get all fields recursively.
     *
     * @return array<string, Field>
     */
    public function getFields(): array
    {
        $fields = [];
        $this->extractFields($this->schema, $fields);

        return $fields;
    }

    /**
     * Extract fields recursively.
     *
     * @param array<int, Component> $components
     * @param array<string, Field>  $fields
     */
    protected function extractFields(array $components, array &$fields): void
    {
        foreach ($components as $component) {
            if ($component instanceof Field) {
                $fields[$component->getName()] = $component;
            } elseif ($component instanceof Component) {
                $refClass = new ReflectionClass($component);
                if ($refClass->hasProperty('schema')) {
                    $prop = $refClass->getProperty('schema');
                    $prop->setAccessible(true);
                    $this->extractFields($prop->getValue($component), $fields);
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

        return new FormData(
            schema: $serializedSchema,
            data: $this->data,
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
        ]);
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
