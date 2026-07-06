<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\ProgressColumn;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class ProgressColumnTest extends TestCase
{
    public function test_progress_column_type(): void
    {
        $column = ProgressColumn::make('stock');
        $this->assertEquals('progress', $column->toArray()['type']);
    }

    public function test_progress_calculation_with_max_value(): void
    {
        $column = ProgressColumn::make('qty')->maxValue(100);
        $record = new class extends Model
        {
            protected $attributes = ['qty' => 45];
        };

        $this->assertEquals(45.0, $column->getProgress($record));
        $this->assertEquals('primary', $column->getProgressColor($record));
    }

    public function test_progress_calculation_with_closure_max_value(): void
    {
        $column = ProgressColumn::make('qty')
            ->maxValue(fn ($r) => $r->min_qty * 5)
            ->color(fn ($val, $r) => $val < $r->min_qty ? 'danger' : 'success');

        $record = new class extends Model
        {
            protected $attributes = ['qty' => 10, 'min_qty' => 20];
        };

        // max = 20 * 5 = 100
        // percent = (10 / 100) * 100 = 10%
        $this->assertEquals(10.0, $column->getProgress($record));
        // color = danger because qty (10) < min_qty (20)
        $this->assertEquals('danger', $column->getProgressColor($record));

        $record2 = new class extends Model
        {
            protected $attributes = ['qty' => 30, 'min_qty' => 20];
        };

        // color = success because qty (30) >= min_qty (20)
        $this->assertEquals('success', $column->getProgressColor($record2));
    }

    public function test_progress_clamping(): void
    {
        $column = ProgressColumn::make('qty')->maxValue(50);

        $recordUnder = new class extends Model
        {
            protected $attributes = ['qty' => -10];
        };
        $recordOver = new class extends Model
        {
            protected $attributes = ['qty' => 100];
        };

        $this->assertEquals(0.0, $column->getProgress($recordUnder));
        $this->assertEquals(100.0, $column->getProgress($recordOver));
    }

    public function test_progress_custom_closure(): void
    {
        $column = ProgressColumn::make('qty')->progress(fn ($val, $r) => 75.5);
        $record = new class extends Model
        {
            protected $attributes = ['qty' => 10];
        };

        $this->assertEquals(75.5, $column->getProgress($record));
    }
}
