<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\ChartWidget;
use Happones\Kinetix\Widgets\HeroWidget;

class HeroWidgetTest extends TestCase
{
    public function test_hero_widget_serializes(): void
    {
        $data = HeroWidget::make()
            ->title('Congratulations Toby! 🎉')
            ->subtitle('Best seller of the month')
            ->value('$15,231.89')
            ->delta('+65% from last month', 'success')
            ->action('View Sales', '/sales')
            ->gradient()
            ->toArray();

        $this->assertSame('hero', $data['type']);
        $this->assertSame('Best seller of the month', $data['data']['subtitle']);
        $this->assertSame('$15,231.89', $data['data']['value']);
        $this->assertSame('+65% from last month', $data['data']['delta']);
        $this->assertSame('success', $data['data']['deltaColor']);
        $this->assertSame('View Sales', $data['data']['actionLabel']);
        $this->assertSame('/sales', $data['data']['actionUrl']);
        $this->assertTrue($data['data']['gradient']);
    }

    public function test_chart_header_metrics_serialize(): void
    {
        $data = ChartWidget::make()
            ->title('Total Revenue')
            ->metric('Desktop', '24,828')
            ->metric('Mobile', '25,010')
            ->metric('Returning', '$42,379', '+2.5%', 'success')
            ->toArray();

        $metrics = $data['data']['metrics'];
        $this->assertCount(3, $metrics);
        $this->assertSame('Desktop', $metrics[0]['label']);
        $this->assertSame('24,828', $metrics[0]['value']);
        $this->assertNull($metrics[0]['badge']);
        $this->assertSame('+2.5%', $metrics[2]['badge']);
        $this->assertSame('success', $metrics[2]['badgeColor']);
    }
}
