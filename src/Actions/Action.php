<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Data\ActionData;

class Action
{
    protected string $name;

    protected string $label;

    protected ?string $icon = null;

    protected ?string $iconPosition = 'before'; // 'before' | 'after'

    protected string|\Closure|null $url = null;

    protected bool $shouldOpenInNewTab = false;

    protected string $color = 'primary'; // primary, danger, warning, success, gray

    protected string $size = 'sm'; // xs, sm, md, lg

    protected string $viewType = 'button'; // button, link

    protected bool $shouldClose = false;

    protected bool $shouldMarkAsRead = false;

    protected bool $shouldMarkAsUnread = false;

    /**
     * Inertia-style dispatch event (dispatched to the parent page component).
     * Works like Livewire's $dispatch / Inertia's event system.
     */
    protected ?string $dispatchEvent = null;

    /**
     * @var array<string, mixed>
     */
    protected array $dispatchData = [];

    /**
     * A custom Inertia visit method (router.visit options).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $inertiaVisit = null;

    public function __construct(string $name)
    {
        $this->name  = $name;
        $this->label = ucfirst($name);
    }

    /**
     * Create a new action instance.
     */
    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * Set the display label of the action button.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Set an icon to display inside the action button.
     * Use the icon name from `@lucide/vue` (e.g. 'trash', 'check').
     */
    public function icon(string $icon, string $position = 'before'): static
    {
        $this->icon         = $icon;
        $this->iconPosition = $position;

        return $this;
    }

    /**
     * Set the URL that the action should navigate to.
     *
     * @param string|\Closure $url
     */
    public function url(string|\Closure $url, bool $shouldOpenInNewTab = false): static
    {
        $this->url                = $url;
        $this->shouldOpenInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    /**
     * Resolve the URL closure for the given record.
     */
    public function resolveUrl(?\Illuminate\Database\Eloquent\Model $record = null): void
    {
        if ($this->url instanceof \Closure) {
            $this->url = ($this->url)($record);
        }
    }

    /**
     * Set the color theme of the action button.
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Set the size of the action button.
     * Accepts: xs, sm, md, lg.
     */
    public function size(string $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Render the action as a button (default).
     */
    public function button(): static
    {
        $this->viewType = 'button';

        return $this;
    }

    /**
     * Render the action as a simple text link.
     */
    public function link(): static
    {
        $this->viewType = 'link';

        return $this;
    }

    /**
     * Close the notification after the action is clicked.
     */
    public function close(): static
    {
        $this->shouldClose = true;

        return $this;
    }

    /**
     * Mark the parent database notification as read when clicked.
     */
    public function markAsRead(): static
    {
        $this->shouldMarkAsRead = true;

        return $this;
    }

    /**
     * Mark the parent database notification as unread when clicked.
     */
    public function markAsUnread(): static
    {
        $this->shouldMarkAsUnread = true;

        return $this;
    }

    /**
     * Dispatch a custom browser event when the action is clicked.
     * In Vue, listen with `window.addEventListener('kinetix:event-name', handler)`.
     *
     * @param array<string, mixed> $data
     */
    public function dispatch(string $event, array $data = []): static
    {
        $this->dispatchEvent = $event;
        $this->dispatchData  = $data;

        return $this;
    }

    /**
     * Trigger an Inertia router.visit() when the action is clicked.
     * Equivalent to router.visit(url, options).
     *
     * @param array<string, mixed> $options
     */
    public function inertiaVisit(string $url, array $options = []): static
    {
        $this->url          = $url;
        $this->inertiaVisit = array_merge(['method' => 'get'], $options);

        return $this;
    }

    /**
     * Convert the action to ActionData.
     */
    public function toData(?\Illuminate\Database\Eloquent\Model $record = null): ActionData
    {
        $url = $this->url;
        if ($url instanceof \Closure) {
            $reflection = new \ReflectionFunction($url);

            if ($record === null && $reflection->getNumberOfRequiredParameters() > 0) {
                $url = null;
            } else {
                $team = request()->route('current_team')
                    ?? request()->route('team')
                    ?? (config('kinetix.teams', false) && auth()->check() && auth()->user()->currentTeam ? auth()->user()->currentTeam : null);

                if ($team) {
                    $defaults = [
                        'current_team' => $team,
                        'team'         => $team,
                    ];

                    if (is_object($team)) {
                        if (method_exists($team, 'getRouteKeyName') && method_exists($team, 'getRouteKey')) {
                            $keyName = $team->getRouteKeyName();
                            $keyValue = $team->getRouteKey();
                            $defaults["current_team:{$keyName}"] = $team;
                            $defaults["team:{$keyName}"]         = $team;
                            $defaults["current_team:{$keyName}"] = $keyValue;
                            $defaults["team:{$keyName}"]         = $keyValue;
                        }
                        if (isset($team->slug)) {
                            $defaults['current_team:slug'] = $team->slug;
                            $defaults['team:slug']         = $team->slug;
                        }
                        if (isset($team->id)) {
                            $defaults['current_team:id'] = $team->id;
                            $defaults['team:id']         = $team->id;
                        }
                    } else {
                        $defaults['current_team:slug'] = $team;
                        $defaults['team:slug']         = $team;
                        $defaults['current_team:id']   = $team;
                        $defaults['team:id']           = $team;
                    }

                    \Illuminate\Support\Facades\URL::defaults($defaults);
                }

                $url = ($url)($record);
            }
        }

        return new ActionData(
            name: $this->name,
            label: $this->label,
            icon: $this->icon,
            iconPosition: $this->iconPosition,
            url: $url,
            shouldOpenInNewTab: $this->shouldOpenInNewTab,
            color: $this->color,
            size: $this->size,
            viewType: $this->viewType,
            shouldClose: $this->shouldClose,
            shouldMarkAsRead: $this->shouldMarkAsRead,
            shouldMarkAsUnread: $this->shouldMarkAsUnread,
            dispatchEvent: $this->dispatchEvent,
            dispatchData: $this->dispatchData,
            inertiaVisit: $this->inertiaVisit,
        );
    }

    /**
     * Convert the action to array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()->toArray();
    }
}
