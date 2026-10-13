<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * A JSON editor: a monospace text area that checks the JSON as it's typed,
 * says where it breaks (line and column), and formats it on demand.
 *
 *     JsonInput::make('settings');            // a JSON string column
 *     JsonInput::make('settings')->decoded(); // a column cast to array/json
 *
 * A value the model already decoded (an array cast) opens formatted. The
 * `json` rule is added, so the server checks it too.
 */
class JsonInput extends Field
{
    protected bool $isDecoded = false;

    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->rules(['json']);
    }

    protected function getType(): string
    {
        return 'json-input';
    }

    /**
     * Save the decoded value (an array) instead of the JSON text: for a
     * column cast to `array`/`json`, which encodes it again.
     */
    public function decoded(bool $condition = true): static
    {
        $this->isDecoded = $condition;

        return $this;
    }

    public function hydrate(mixed $value, ?Model $record = null): mixed
    {
        if ($this->afterStateHydrated !== null) {
            return parent::hydrate($value, $record);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    public function dehydrate(mixed $value, ?Model $record = null): mixed
    {
        if ($this->dehydrateStateUsing !== null) {
            return parent::dehydrate($value, $record);
        }

        if (! $this->isDecoded || ! is_string($value)) {
            return $value;
        }

        if (trim($value) === '') {
            return null;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Validation refuses it first; never save half-decoded state.
            return $value;
        }
    }
}
