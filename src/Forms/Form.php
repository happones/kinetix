<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Data\FormData;
use Happones\Kinetix\Forms\Components\Component;
use Happones\Kinetix\Forms\Components\Field;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use JsonSerializable;
use ReflectionClass;

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
                $value = data_get($data, $name);
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
     * @return array<string, array<int, string>>
     */
    public function getValidationRules(): array
    {
        $rules  = [];
        $fields = $this->getFields();
        foreach ($fields as $name => $field) {
            if (! $field->isHidden($this->operation, $this->record)) {
                $rules[$name] = $field->getRules($this->record);
            }
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
            $this->getValidationRules(),
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
            if (! $field->isHidden($this->operation, $this->record) && $field->isSaved()) {
                $value        = $data[$name] ?? $field->getDefaultValue($this->record);
                $state[$name] = $field->dehydrate($value, $this->record);
            }
        }

        return $state;
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
     * Convert to Spatie FormData.
     */
    public function toData(): FormData
    {
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
            rules: $this->getValidationRules(),
            operation: $this->operation,
            precognitive: $this->precognitive,
            validationUrl: $this->validationUrl,
            validationMethod: $this->validationMethod,
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
