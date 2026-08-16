<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

use Happones\Kinetix\Data\SpotlightItemData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A static spotlight entry — a navigation link (`url`) or a fast action
 * (`event`, dispatched on the client). Matches the query against its label and
 * keywords; shown unfiltered when the query is empty.
 */
class SpotlightLink implements HasSpotlightPriority, SpotlightSource
{
    protected ?string $url = null;

    protected ?string $event = null;

    protected ?string $icon = null;

    protected string $group = 'Navigation';

    protected ?string $ability = null;

    protected int $priority = 0;

    /** @var array<int, string> */
    protected array $keywords = [];

    final public function __construct(public string $label) {}

    public static function make(string $label): static
    {
        return new static($label);
    }

    public function url(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function event(string $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function icon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function group(string $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function authorize(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * @param array<int, string> $keywords
     */
    public function keywords(array $keywords): static
    {
        $this->keywords = $keywords;

        return $this;
    }

    /** Higher sorts this link's group first. */
    public function priority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function authorizedFor(?Authenticatable $user): bool
    {
        return $this->ability === null || Gate::allows($this->ability);
    }

    /**
     * @return array<int, SpotlightItemData>
     */
    public function search(string $query): array
    {
        if ($query !== '' && ! $this->matches($query)) {
            return [];
        }

        return [
            new SpotlightItemData(
                type: $this->event !== null ? 'action' : 'link',
                group: $this->group,
                title: $this->label,
                subtitle: null,
                url: $this->url,
                event: $this->event,
                icon: $this->icon,
                id: null,
            ),
        ];
    }

    protected function matches(string $query): bool
    {
        $haystack = Str::lower($this->label.' '.implode(' ', $this->keywords));

        return str_contains($haystack, Str::lower($query));
    }
}
