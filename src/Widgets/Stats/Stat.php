<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets\Stats;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Stat implements Arrayable, JsonSerializable
{
    protected string $label;

    protected mixed $value;

    protected ?string $description = null;

    protected ?string $descriptionIcon = null;

    protected ?string $descriptionColor = 'gray'; // success, danger, warning, info, gray

    protected ?string $icon = null;

    protected ?string $iconColor = 'info'; // success, danger, warning, info, gray, primary

    protected ?string $badge = null;

    protected ?string $badgeColor = 'gray';

    protected ?string $linkLabel = null;

    protected ?string $linkUrl = null;

    protected array $chart = [];

    public function __construct(string $label, mixed $value)
    {
        $this->label = $label;
        $this->value = $value;
    }

    public static function make(string $label, mixed $value): static
    {
        return new static($label, $value);
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function descriptionIcon(string $icon): static
    {
        $this->descriptionIcon = $icon;

        return $this;
    }

    public function descriptionColor(string $color): static
    {
        $this->descriptionColor = $color;

        return $this;
    }

    public function chart(array $chart): static
    {
        $this->chart = $chart;

        return $this;
    }

    /**
     * A leading icon shown in a colored badge on the card.
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * The icon badge color: success | danger | warning | info | gray | primary.
     */
    public function iconColor(string $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    /**
     * A small trend chip shown in the card header (e.g. "+12.5%").
     */
    public function badge(string $badge, string $color = 'gray'): static
    {
        $this->badge      = $badge;
        $this->badgeColor = $color;

        return $this;
    }

    /**
     * A footer link (e.g. "View more →").
     */
    public function url(string $label, string $url): static
    {
        $this->linkLabel = $label;
        $this->linkUrl   = $url;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'label'            => $this->label,
            'value'            => $this->value,
            'description'      => $this->description,
            'descriptionIcon'  => $this->descriptionIcon,
            'descriptionColor' => $this->descriptionColor,
            'icon'             => $this->icon,
            'iconColor'        => $this->iconColor,
            'badge'            => $this->badge,
            'badgeColor'       => $this->badgeColor,
            'linkLabel'        => $this->linkLabel,
            'linkUrl'          => $this->linkUrl,
            'chart'            => $this->chart,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
