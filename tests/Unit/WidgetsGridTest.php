<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\HeroWidget;
use Happones\Kinetix\Widgets\WidgetsGrid;

class WidgetsGridTest extends TestCase
{
    public function test_default_layout_is_grid_not_dense_with_a_1_5rem_gap(): void
    {
        $data = WidgetsGrid::make()->widgets([HeroWidget::make()])->toArray();

        $this->assertSame('grid', $data['layout']);
        $this->assertFalse($data['dense']);
        $this->assertSame('1.5rem', $data['gap']);
    }

    public function test_gap_accepts_a_bare_value_or_a_responsive_map(): void
    {
        $bare = WidgetsGrid::make()->gap('2rem')->widgets([])->toArray();
        $this->assertSame('2rem', $bare['gap']);

        $responsive = WidgetsGrid::make()
            ->gap(['default' => '1rem', 'lg' => '2rem'])
            ->widgets([])
            ->toArray();
        $this->assertSame(['default' => '1rem', 'lg' => '2rem'], $responsive['gap']);
    }

    public function test_dense_toggles_grid_auto_flow_dense(): void
    {
        $data = WidgetsGrid::make()->dense()->widgets([])->toArray();
        $this->assertTrue($data['dense']);
        $this->assertSame('grid', $data['layout']); // dense doesn't change the layout mode

        $off = WidgetsGrid::make()->dense(false)->widgets([])->toArray();
        $this->assertFalse($off['dense']);
    }

    public function test_masonry_switches_the_layout_mode_with_a_default_column_count(): void
    {
        $data = WidgetsGrid::make()->masonry()->widgets([])->toArray();
        $this->assertSame('masonry', $data['layout']);
        $this->assertSame(3, $data['masonryColumns']);
    }

    public function test_masonry_accepts_a_bare_or_responsive_column_count(): void
    {
        $bare = WidgetsGrid::make()->masonry(4)->widgets([])->toArray();
        $this->assertSame(4, $bare['masonryColumns']);

        $responsive = WidgetsGrid::make()
            ->masonry(['default' => 1, 'md' => 2, 'lg' => 4])
            ->widgets([])
            ->toArray();
        $this->assertSame(['default' => 1, 'md' => 2, 'lg' => 4], $responsive['masonryColumns']);
    }
}
