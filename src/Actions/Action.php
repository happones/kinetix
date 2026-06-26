<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Data\ActionData;
use Happones\Kinetix\Support\Concerns\HasAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class Action
{
    use HasAuthorization;

    protected string $name;

    protected string $label;

    protected ?string $icon = null;

    protected ?string $iconPosition = 'before'; // 'before' | 'after'

    protected string|\Closure|null $url = null;

    protected bool $shouldOpenInNewTab = false;

    protected string $color = 'primary'; // primary, danger, warning, success, gray

    protected ?string $shortcut = null; // keyboard shortcut, e.g. 'c', 'mod+e'

    protected string $size = 'sm'; // xs, sm, md, lg

    protected string $viewType = 'button'; // button, link

    protected bool $isIconButton = false;

    protected bool $shouldClose = false;

    protected bool $shouldMarkAsRead = false;

    protected bool $shouldMarkAsUnread = false;

    protected bool $isDownload = false;

    protected bool $isPreview = false;

    protected ?string $previewType = null; // 'auto' | 'image' | 'pdf'

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

    /**
     * Background HTTP request options (fire-and-forget XHR, not an Inertia
     * visit — so the endpoint may return JSON without triggering Inertia's
     * "invalid response" modal). Shape: {method, toast?}.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $httpRequest = null;

    protected bool $requiresConfirmation = false;

    protected ?string $modalHeading = null;

    protected ?string $modalDescription = null;

    protected ?string $modalIcon = null;

    protected ?string $modalSubmitActionLabel = null;

    protected ?string $modalCancelActionLabel = null;

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
    /**
     * Set the action's icon, or pass null to remove it (e.g. on a prebuilt
     * action whose default icon you don't want).
     */
    public function icon(?string $icon, string $position = 'before'): static
    {
        $this->icon         = $icon;
        $this->iconPosition = $position;

        return $this;
    }

    /**
     * Set the URL that the action should navigate to.
     */
    public function url(string|\Closure $url, bool $shouldOpenInNewTab = false): static
    {
        $this->url                = $url;
        $this->shouldOpenInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    /**
     * Force the browser to download `url` (attachment) instead of navigating.
     */
    public function download(bool $condition = true): static
    {
        $this->isDownload = $condition;

        return $this;
    }

    /**
     * Open `url` in the file-preview modal (image / PDF) instead of navigating.
     * Pass an explicit type, or leave 'auto' to detect from the URL extension.
     */
    public function preview(string $type = 'auto'): static
    {
        $this->isPreview   = true;
        $this->previewType = $type;

        return $this;
    }

    /**
     * Resolve the URL closure for the given record.
     */
    public function resolveUrl(?Model $record = null): void
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
     * Bind a keyboard shortcut (e.g. 'c', 'mod+e', 'g i') to this action. Honored
     * when the action is rendered in a `KinetixPageHeader`.
     */
    public function shortcut(?string $keys): static
    {
        $this->shortcut = $keys;

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
     * Render the action as a compact icon-only button (no label, no outline) —
     * the shadcn row-action style. The label is kept for accessibility/tooltip.
     */
    public function iconButton(bool $condition = true): static
    {
        $this->isIconButton = $condition;

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
     * Require the user to confirm via a modal before the action runs.
     *
     * Optionally pass the modal heading directly: `requiresConfirmation('Delete user?')`.
     */
    public function requiresConfirmation(bool|string $condition = true): static
    {
        if (is_string($condition)) {
            $this->requiresConfirmation = true;
            $this->modalHeading         = $condition;

            return $this;
        }

        $this->requiresConfirmation = $condition;

        return $this;
    }

    /**
     * Set the confirmation modal heading.
     */
    public function modalHeading(string $heading): static
    {
        $this->modalHeading = $heading;

        return $this;
    }

    /**
     * Set the confirmation modal description / body text.
     */
    public function modalDescription(string $description): static
    {
        $this->modalDescription = $description;

        return $this;
    }

    /**
     * Set the confirmation modal icon (Lucide name, e.g. 'alert-triangle').
     */
    public function modalIcon(string $icon): static
    {
        $this->modalIcon = $icon;

        return $this;
    }

    /**
     * Set the label for the confirm button.
     */
    public function modalSubmitActionLabel(string $label): static
    {
        $this->modalSubmitActionLabel = $label;

        return $this;
    }

    /**
     * Set the label for the cancel button.
     */
    public function modalCancelActionLabel(string $label): static
    {
        $this->modalCancelActionLabel = $label;

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
     * Equivalent to router.visit(url, options). The URL may be a Closure
     * receiving the record, so per-row actions can build a per-record URL
     * (e.g. a DELETE to `route('posts.destroy', $post)`).
     *
     * @param array<string, mixed> $options
     */
    /**
     * Fire a background HTTP request (plain XHR, NOT an Inertia visit) when
     * clicked — use when the endpoint returns JSON and you just want a toast,
     * without navigating or triggering Inertia's response modal. Options:
     * `method` (default 'post') and `toast` (success message to show).
     *
     * @param array<string, mixed> $options
     */
    public function request(string|\Closure $url, array $options = []): static
    {
        $this->url         = $url;
        $this->httpRequest = array_merge(['method' => 'post'], $options);

        return $this;
    }

    public function inertiaVisit(string|\Closure $url, array $options = []): static
    {
        $this->url          = $url;
        $this->inertiaVisit = array_merge(['method' => 'get'], $options);

        return $this;
    }

    /**
     * Convert the action to ActionData, or null when hidden/unauthorized.
     */
    public function toData(?Model $record = null): ?ActionData
    {
        if (! $this->shouldRender($record)) {
            return null;
        }

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
                            $keyName                             = $team->getRouteKeyName();
                            $keyValue                            = $team->getRouteKey();
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

                    URL::defaults($defaults);
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
            httpRequest: $this->httpRequest,
            requiresConfirmation: $this->requiresConfirmation,
            modalHeading: $this->modalHeading,
            modalDescription: $this->modalDescription,
            modalIcon: $this->modalIcon,
            modalSubmitActionLabel: $this->modalSubmitActionLabel,
            modalCancelActionLabel: $this->modalCancelActionLabel,
            isDownload: $this->isDownload,
            isPreview: $this->isPreview,
            previewType: $this->previewType,
            shortcut: $this->shortcut,
            isIconButton: $this->isIconButton,
        );
    }

    /**
     * Convert the action to array format (empty when hidden/unauthorized).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()?->toArray() ?? [];
    }

    /**
     * Serialize a set of actions for a context, dropping any that are hidden or
     * unauthorized. Prefer this over mapping toArray() so the payload only ever
     * contains actions the current user may perform.
     *
     * @param  array<int, Action|ActionGroup>   $actions
     * @return array<int, array<string, mixed>>
     */
    public static function toArrayMany(array $actions, ?Model $record = null): array
    {
        $serialized = [];

        foreach ($actions as $action) {
            $data = $action->toData($record);
            if ($data !== null) {
                $serialized[] = $data->toArray();
            }
        }

        return $serialized;
    }
}
