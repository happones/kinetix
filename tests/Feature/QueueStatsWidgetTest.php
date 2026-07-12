<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\QueueStatsWidget;

class QueueStatsWidgetTest extends TestCase
{
    public function test_queue_stats_widget_serializes(): void
    {
        $data = QueueStatsWidget::make()
            ->columnSpan(['default' => 12, 'lg' => 6])
            ->sort(2)
            ->toArray();

        $this->assertSame('queue-stats', $data['type']);
        $this->assertSame(['default' => 12, 'lg' => 6], $data['columnSpan']);
        $this->assertSame(2, $data['sort']);
        // No payload — the Vue component self-polls via useKinetixQueue().
        $this->assertSame([], $data['data']);
    }

    public function test_queue_stats_widget_respects_authorization(): void
    {
        $widget = QueueStatsWidget::make()->authorize(false);

        $this->assertFalse($widget->shouldRender());
    }
}
