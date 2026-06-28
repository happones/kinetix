<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\ProgressWidget;

class ProgressWidgetTest extends TestCase
{
    public function test_it_computes_percent_from_value_and_target(): void
    {
        $data = ProgressWidget::make()
            ->title('Monthly goal')
            ->value(7200)
            ->target(10000)
            ->display('$7,200')
            ->caption('of $10,000')
            ->color('success')
            ->toArray();

        $this->assertSame('progress', $data['type']);
        $this->assertSame(72, $data['data']['percent']);
        $this->assertSame('$7,200', $data['data']['display']);
        $this->assertSame('of $10,000', $data['data']['caption']);
        $this->assertSame('success', $data['data']['color']);
        $this->assertFalse($data['data']['ring']);
    }

    public function test_percent_is_clamped_and_defaults_to_a_percentage_display(): void
    {
        $data = ProgressWidget::make()
            ->value(140)
            ->target(100)
            ->ring()
            ->toArray()['data'];

        $this->assertSame(100, $data['percent']);
        $this->assertSame('100%', $data['display']); // no explicit display() → percentage
        $this->assertTrue($data['ring']);
    }

    public function test_a_zero_target_yields_zero_percent_without_dividing(): void
    {
        $data = ProgressWidget::make()
            ->value(50)
            ->target(0)
            ->toArray()['data'];

        $this->assertSame(0, $data['percent']);
        $this->assertSame('0%', $data['display']);
    }
}
