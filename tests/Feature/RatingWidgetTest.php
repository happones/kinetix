<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\RatingWidget;

class RatingWidgetTest extends TestCase
{
    public function test_it_serializes_average_total_and_breakdown(): void
    {
        $data = RatingWidget::make()
            ->title('Customer reviews')
            ->average(4.5)
            ->total(5500)
            ->breakdown([5 => 4000, 4 => 2100, 3 => 800, 2 => 631, 1 => 344])
            ->toArray();

        $this->assertSame('rating', $data['type']);
        $this->assertSame(4.5, $data['data']['average']);
        $this->assertSame(5500, $data['data']['total']);
        $this->assertSame(5, $data['data']['max']);

        // Breakdown is ordered high→low with computed percentages.
        $rows = $data['data']['breakdown'];
        $this->assertCount(5, $rows);
        $this->assertSame(5, $rows[0]['level']);
        $this->assertSame(4000, $rows[0]['count']);
        $this->assertSame(100, $rows[0]['pct']);          // the max count
        $this->assertSame(53, $rows[1]['pct']);           // 2100/4000 ≈ 53%
        $this->assertSame(1, $rows[4]['level']);
    }

    public function test_missing_levels_default_to_zero(): void
    {
        $rows = RatingWidget::make()
            ->breakdown([5 => 10])
            ->toArray()['data']['breakdown'];

        $this->assertSame(10, $rows[0]['count']);
        $this->assertSame(0, $rows[1]['count']); // level 4 absent
        $this->assertSame(0, $rows[1]['pct']);
    }
}
