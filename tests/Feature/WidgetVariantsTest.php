<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\Lists\ListItem;
use Happones\Kinetix\Widgets\ListWidget;
use Happones\Kinetix\Widgets\Stats\Stat;
use Happones\Kinetix\Widgets\StatsOverviewWidget;

class WidgetVariantsTest extends TestCase
{
    public function test_stat_serializes_icon_and_color(): void
    {
        $stat = Stat::make('Sales today', '$502.30')
            ->icon('dollar-sign')
            ->iconColor('info')
            ->description('+12.5% vs yesterday')
            ->descriptionIcon('arrow-up')
            ->descriptionColor('success')
            ->toArray();

        $this->assertSame('dollar-sign', $stat['icon']);
        $this->assertSame('info', $stat['iconColor']);
        $this->assertSame('+12.5% vs yesterday', $stat['description']);
        $this->assertSame('success', $stat['descriptionColor']);
    }

    public function test_stats_overview_widget_carries_icon_stats(): void
    {
        $data = StatsOverviewWidget::make()
            ->stats([Stat::make('Customers', 3)->icon('users')->iconColor('success')])
            ->toArray();

        $this->assertSame('stats', $data['type']);
        $this->assertSame('users', $data['data']['stats'][0]['icon']);
    }

    public function test_list_item_serializes_all_fields(): void
    {
        $item = ListItem::make('Jugo Del Valle 1L')
            ->subtitle('Out of stock')
            ->icon('alert-triangle', 'danger')
            ->value('$97.44')
            ->badge('0', 'danger')
            ->progress(20)
            ->url('/products/1')
            ->toArray();

        $this->assertSame('Jugo Del Valle 1L', $item['title']);
        $this->assertSame('Out of stock', $item['subtitle']);
        $this->assertSame('alert-triangle', $item['icon']);
        $this->assertSame('danger', $item['iconColor']);
        $this->assertSame('$97.44', $item['value']);
        $this->assertSame('0', $item['badge']);
        $this->assertSame('danger', $item['badgeColor']);
        $this->assertSame(20, $item['progress']);
        $this->assertSame('/products/1', $item['url']);
    }

    public function test_progress_is_clamped(): void
    {
        $this->assertSame(100, ListItem::make('x')->progress(250)->toArray()['progress']);
        $this->assertSame(0, ListItem::make('x')->progress(-5)->toArray()['progress']);
    }

    public function test_list_widget_serializes_items_and_action(): void
    {
        $data = ListWidget::make()
            ->title('Stock alerts')
            ->icon('alert-triangle')
            ->items([
                ListItem::make('A')->badge('0', 'danger'),
                ListItem::make('B')->progress(40),
            ])
            ->action('View inventory', '/inventory')
            ->toArray();

        $this->assertSame('list', $data['type']);
        $this->assertSame('alert-triangle', $data['data']['icon']);
        $this->assertCount(2, $data['data']['items']);
        $this->assertSame('A', $data['data']['items'][0]['title']);
        $this->assertSame('View inventory', $data['data']['actionLabel']);
        $this->assertSame('/inventory', $data['data']['actionUrl']);
    }
}
