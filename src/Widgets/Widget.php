<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

abstract class Widget implements Arrayable, JsonSerializable
{
    protected string $id;

    protected string $type;

    protected ?string $title = null;

    protected ?string $description = null;

    protected int|string|array $columnSpan = 12;

    protected ?int $sort = 0;

    public function __construct()
    {
        $this->id = uniqid('widget_', true);
    }

    public static function make(): static
    {
        return new static;
    }

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function columnSpan(int|string|array $columnSpan): static
    {
        $this->columnSpan = $columnSpan;

        return $this;
    }

    public function sort(int $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    abstract protected function getData(): array;

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'title'       => $this->title,
            'description' => $this->description,
            'columnSpan'  => $this->columnSpan,
            'sort'        => $this->sort,
            'data'        => $this->getData(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
