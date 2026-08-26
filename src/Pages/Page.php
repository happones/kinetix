<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pages;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\ActionData;
use Happones\Kinetix\Data\PageData;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * A page's chrome, declared in PHP: its heading, its description and the two
 * action bars around whatever it renders.
 *
 * It deliberately says NOTHING about the body. A page built with this can hold a
 * table, a form, or a Vue component entirely of your own — the point is that the
 * actions are declared server-side (where authorization, routes and translations
 * live) while the content stays free:
 *
 *     return inertia('Inventory/Adjust', [
 *         'page' => Page::make(__('inventory.adjust'))
 *             ->description(__('inventory.adjust_hint'))
 *             ->headerActions([
 *                 Action::make('history')->label(__('inventory.history'))->icon('history')
 *                     ->url(route('inventory.history', $item)),
 *             ])
 *             ->footerActions([
 *                 Action::make('cancel')->label(__('inventory.cancel'))->color('gray')
 *                     ->url(route('inventory.index')),
 *                 Action::make('post')->label(__('inventory.post'))->icon('check')
 *                     ->requiresConfirmation(__('inventory.post_confirm'))
 *                     ->inertiaVisit(route('inventory.post', $item), ['method' => 'post']),
 *             ])
 *             ->record($item)
 *             ->stickyFooter(),
 *     ]);
 *
 * On the frontend, `<KinetixPageShell :page="page">` renders the header, your
 * content and the footer. Composing `<KinetixPageHeader>` and
 * `<KinetixPageFooter>` by hand stays perfectly valid — this class is the
 * convenience of declaring both at once, not a requirement.
 *
 * Subclass it to keep a page's chrome next to the rest of its code
 * (`kinetix:make-page` scaffolds exactly that), overriding {@see heading()},
 * {@see description()}, {@see buildHeaderActions()} and
 * {@see buildFooterActions()}.
 */
class Page implements Arrayable, JsonSerializable
{
    protected ?string $heading = null;

    protected ?string $description = null;

    /**
     * @var array<int, Action>
     */
    protected array $headerActions = [];

    /**
     * @var array<int, Action>
     */
    protected array $footerActions = [];

    /**
     * The record the page is about, passed to every action so `->url()`
     * closures and authorization callbacks receive it.
     */
    protected ?Model $record = null;

    protected bool $stickyFooter = false;

    public function __construct(?string $heading = null)
    {
        $this->heading ??= $heading;
        // Subclass hooks, so a dedicated Page class declares its own chrome.
        $this->headerActions = $this->buildHeaderActions();
        $this->footerActions = $this->buildFooterActions();
    }

    public static function make(?string $heading = null): static
    {
        return new static($heading);
    }

    /**
     * Override in a dedicated Page subclass to declare its header actions.
     *
     * @return array<int, Action>
     */
    protected function buildHeaderActions(): array
    {
        return [];
    }

    /**
     * Override in a dedicated Page subclass to declare its footer actions.
     *
     * @return array<int, Action>
     */
    protected function buildFooterActions(): array
    {
        return [];
    }

    public function heading(?string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param array<int, Action> $actions
     */
    public function headerActions(array $actions): static
    {
        $this->headerActions = $actions;

        return $this;
    }

    /**
     * @param array<int, Action> $actions
     */
    public function footerActions(array $actions): static
    {
        $this->footerActions = $actions;

        return $this;
    }

    /**
     * The record the page is about. Actions are serialized against it, so a
     * `->url(fn ($record) => …)` closure and an `->authorize()` check both see
     * it — set this whenever the page is about one model.
     */
    public function record(?Model $record): static
    {
        $this->record = $record;

        return $this;
    }

    /**
     * Pin the footer bar to the bottom of the scroll container, for a long page
     * where the primary action should stay reachable.
     */
    public function stickyFooter(bool $condition = true): static
    {
        $this->stickyFooter = $condition;

        return $this;
    }

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<int, Action>
     */
    public function getHeaderActions(): array
    {
        return $this->headerActions;
    }

    /**
     * @return array<int, Action>
     */
    public function getFooterActions(): array
    {
        return $this->footerActions;
    }

    public function hasStickyFooter(): bool
    {
        return $this->stickyFooter;
    }

    public function toData(): PageData
    {
        return new PageData(
            heading: $this->heading,
            description: $this->description,
            // `toData()` returns null for an action the user may not run or that
            // hid itself, so an unauthorized action never reaches the page.
            headerActions: $this->serializeActions($this->headerActions),
            footerActions: $this->serializeActions($this->footerActions),
            stickyFooter: $this->stickyFooter,
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

    /**
     * @param  array<int, Action>     $actions
     * @return array<int, ActionData>
     */
    protected function serializeActions(array $actions): array
    {
        return array_values(array_filter(
            array_map(fn (Action $action) => $action->toData($this->record), $actions)
        ));
    }
}
