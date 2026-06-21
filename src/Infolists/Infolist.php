<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists;

use Happones\Kinetix\Data\InfolistData;
use Happones\Kinetix\Infolists\Components\Component;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

class Infolist implements Arrayable, JsonSerializable
{
    /**
     * @var array<int, Component>
     */
    protected array $schema = [];

    protected ?Model $record = null;

    protected int $columns = 1;

    protected string $operation = 'view';

    public function __construct(?Model $record = null)
    {
        $this->record = $record;
        $this->schema = $this->buildSchema();
    }

    /**
     * Override in a dedicated Infolist subclass to declare a default schema.
     *
     * @return array<int, Component>
     */
    protected function buildSchema(): array
    {
        return [];
    }

    public static function make(?Model $record = null): static
    {
        return new static($record);
    }

    /**
     * Build and serialize the infolist for the given record in one call.
     *
     * @return array<string, mixed>
     */
    public static function render(?Model $record = null): array
    {
        return static::make($record)->record($record)->toArray();
    }

    /**
     * Set the schema components.
     *
     * @param array<int, Component> $components
     */
    public function schema(array $components): static
    {
        $this->schema = $components;

        return $this;
    }

    public function record(?Model $record): static
    {
        $this->record = $record;

        if ($record !== null && $this->operation === 'view') {
            $this->operation = 'view';
        }

        return $this;
    }

    public function columns(int $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    public function operation(string $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    /**
     * Convert the infolist to its Spatie data object.
     */
    public function toData(): InfolistData
    {
        $serializedSchema = [];
        foreach ($this->schema as $component) {
            $componentData = $component->toData($this->operation, $this->record);
            if ($componentData !== null) {
                $serializedSchema[] = $componentData;
            }
        }

        return new InfolistData(
            schema: $serializedSchema,
            columns: $this->columns,
            operation: $this->operation,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()->toArray();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
