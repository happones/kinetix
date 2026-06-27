<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

use Happones\Kinetix\Widgets\Lists\ListItem;

/**
 * A list/feed widget: rows with a leading icon, title + subtitle, an optional
 * trailing value/badge and progress bar — for "recent activity", "stock
 * alerts", "latest orders" and similar dashboard panels. An optional footer
 * action renders a link button.
 *
 *     ListWidget::make()
 *         ->title('Stock alerts')
 *         ->items([
 *             ListItem::make('Jugo Del Valle 1L')->subtitle('Out of stock')
 *                 ->icon('alert-triangle', 'danger')->badge('0', 'danger'),
 *             ListItem::make('Sabritas 45g')->progress(20)->value('3'),
 *         ])
 *         ->action('View inventory', '/inventory');
 */
class ListWidget extends Widget
{
    protected string $type = 'list';

    /**
     * @var array<int, ListItem>
     */
    protected array $items = [];

    protected ?string $icon = null;

    protected ?string $actionLabel = null;

    protected ?string $actionUrl = null;

    protected ?string $emptyState = null;

    /**
     * @param array<int, ListItem> $items
     */
    public function items(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    /**
     * A header icon shown next to the widget title.
     */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * A footer link button.
     */
    public function action(string $label, string $url): static
    {
        $this->actionLabel = $label;
        $this->actionUrl   = $url;

        return $this;
    }

    public function emptyState(string $text): static
    {
        $this->emptyState = $text;

        return $this;
    }

    protected function getData(): array
    {
        return [
            'icon'        => $this->icon,
            'items'       => array_map(static fn (ListItem $item): array => $item->toArray(), $this->items),
            'actionLabel' => $this->actionLabel,
            'actionUrl'   => $this->actionUrl,
            'emptyState'  => $this->emptyState,
        ];
    }
}
