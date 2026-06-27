<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets\Lists;

use Happones\Kinetix\Widgets\ListWidget;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A single row in a {@see ListWidget}: a leading icon,
 * a title + subtitle, an optional trailing value and/or badge, and an optional
 * progress bar.
 */
class ListItem implements Arrayable, JsonSerializable
{
    protected ?string $subtitle = null;

    protected ?string $icon = null;

    protected ?string $iconColor = 'gray'; // success, danger, warning, info, gray, primary

    protected ?string $value = null;

    protected ?string $badge = null;

    protected ?string $badgeColor = 'gray';

    protected ?int $progress = null;

    protected ?string $url = null;

    public function __construct(protected string $title) {}

    public static function make(string $title): static
    {
        return new static($title);
    }

    public function subtitle(string $subtitle): static
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function icon(string $icon, string $color = 'gray'): static
    {
        $this->icon      = $icon;
        $this->iconColor = $color;

        return $this;
    }

    public function value(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function badge(string $badge, string $color = 'gray'): static
    {
        $this->badge      = $badge;
        $this->badgeColor = $color;

        return $this;
    }

    /**
     * A 0–100 progress bar shown under the row.
     */
    public function progress(int $percent): static
    {
        $this->progress = max(0, min(100, $percent));

        return $this;
    }

    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title'      => $this->title,
            'subtitle'   => $this->subtitle,
            'icon'       => $this->icon,
            'iconColor'  => $this->iconColor,
            'value'      => $this->value,
            'badge'      => $this->badge,
            'badgeColor' => $this->badgeColor,
            'progress'   => $this->progress,
            'url'        => $this->url,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
