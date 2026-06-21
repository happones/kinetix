<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Data\RelationManagerData;
use Happones\Kinetix\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use RuntimeException;

abstract class RelationManager implements Arrayable, JsonSerializable
{
    /**
     * The relationship method name on the parent model (e.g. 'posts').
     */
    protected static string $relationship;

    /**
     * Optional heading; defaults to a humanized relationship name.
     */
    protected static ?string $title = null;

    protected ?Model $parent = null;

    public function __construct(?Model $parent = null)
    {
        $this->parent = $parent;
    }

    public static function make(?Model $parent = null): static
    {
        return new static($parent);
    }

    /**
     * Configure the table that lists the related records.
     */
    abstract public function table(Table $table): Table;

    public static function getTitle(): string
    {
        return static::$title ?? (string) str(static::$relationship)->headline();
    }

    public static function getRelationship(): string
    {
        return static::$relationship;
    }

    /**
     * The Eloquent query for the parent's related records.
     */
    public function getRelationshipQuery(): Builder
    {
        if ($this->parent === null) {
            throw new RuntimeException('A parent record is required to resolve '.static::class.'.');
        }

        return $this->parent->{static::$relationship}()->getQuery();
    }

    public function toData(): RelationManagerData
    {
        $table = $this->table(
            Table::make($this->getRelationshipQuery())->queryPrefix(static::$relationship.'_')
        );

        return new RelationManagerData(
            title: static::getTitle(),
            relationship: static::$relationship,
            table: $table->toData(),
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
