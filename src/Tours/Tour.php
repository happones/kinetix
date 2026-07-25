<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tours;

/**
 * A declared product tour. Bind it to a page (Inertia component name) or a URL
 * pattern (`*` wildcards) and the global `<KinetixTours />` host auto-starts it
 * for users who haven't seen it — gated by an optional permission so a tour
 * never points at UI its viewer can't use.
 *
 *     KinetixTours::tour('posts')
 *         ->page('Kinetix/Posts/Index')
 *         ->permission('posts.viewAny')
 *         ->steps([
 *             TourStep::make('[data-tour=create]')->title(__('tours.create')),
 *         ]);
 */
class Tour
{
    protected ?string $page = null;

    protected ?string $url = null;

    protected ?string $permission = null;

    protected bool $auto = true;

    /**
     * @var array<int, TourStep>
     */
    protected array $steps = [];

    public function __construct(public readonly string $id) {}

    /**
     * Match by Inertia page component name (e.g. `Kinetix/Posts/Index`;
     * `*` wildcards allowed).
     */
    public function page(string $component): static
    {
        $this->page = $component;

        return $this;
    }

    /**
     * Match by URL path (e.g. `/posts*`; `*` wildcards allowed). With teams,
     * patterns match after the `{current_team}` segment too — prefer page().
     */
    public function url(string $pattern): static
    {
        $this->url = $pattern;

        return $this;
    }

    /**
     * The Gate ability required to receive this tour (checked server-side —
     * denied users never get the steps in their payload).
     */
    public function permission(string $ability): static
    {
        $this->permission = $ability;

        return $this;
    }

    /**
     * Whether the tour auto-starts on its first matching visit (default true).
     * Set false for tours only launched manually (help menu, replay button).
     */
    public function auto(bool $auto = true): static
    {
        $this->auto = $auto;

        return $this;
    }

    /**
     * @param array<int, TourStep> $steps
     */
    public function steps(array $steps): static
    {
        $this->steps = $steps;

        return $this;
    }

    public function step(TourStep $step): static
    {
        $this->steps[] = $step;

        return $this;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    /**
     * @return array{id: string, page: ?string, url: ?string, auto: bool, steps: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id'    => $this->id,
            'page'  => $this->page,
            'url'   => $this->url,
            'auto'  => $this->auto,
            'steps' => array_map(static fn (TourStep $step): array => $step->toArray(), $this->steps),
        ];
    }
}
