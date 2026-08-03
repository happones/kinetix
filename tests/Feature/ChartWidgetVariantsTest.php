<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\ChartWidget;

class ChartWidgetVariantsTest extends TestCase
{
    public function test_it_serializes_chart_variant_options(): void
    {
        $data = ChartWidget::make()
            ->chartType('horizontalBar')
            ->labels(['Drinks', 'Food'])
            ->datasets([['label' => 'Sales', 'data' => [3200, 2400]]])
            ->stacked()
            ->legend()
            ->centerLabel('10.2K', 'Visitors')
            ->toArray();

        $this->assertSame('chart', $data['type']);
        $this->assertSame('horizontalBar', $data['data']['chartType']);
        $this->assertTrue($data['data']['stacked']);
        $this->assertTrue($data['data']['legend']);
        $this->assertSame('10.2K', $data['data']['centerValue']);
        $this->assertSame('Visitors', $data['data']['centerLabel']);
    }

    public function test_variant_flags_default_off(): void
    {
        $data = ChartWidget::make()->chartType('line')->toArray()['data'];

        $this->assertFalse($data['stacked']);
        // Null = auto: the frontend decides from the series count.
        $this->assertNull($data['legend']);
        $this->assertNull($data['centerValue']);
    }

    public function test_legend_can_be_forced_off(): void
    {
        $data = ChartWidget::make()->chartType('line')->legend(false)->toArray()['data'];

        $this->assertFalse($data['legend']);
    }
}
