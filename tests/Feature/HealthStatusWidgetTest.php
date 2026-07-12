<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\HealthStatusWidget;

class HealthStatusWidgetTest extends TestCase
{
    public function test_health_status_widget_serializes(): void
    {
        $data = HealthStatusWidget::make()
            ->columnSpan(['default' => 12, 'lg' => 6])
            ->sort(3)
            ->toArray();

        $this->assertSame('health-status', $data['type']);
        $this->assertSame(['default' => 12, 'lg' => 6], $data['columnSpan']);
        $this->assertSame(3, $data['sort']);
        // No payload — the Vue component self-polls via useKinetixHealth().
        $this->assertSame([], $data['data']);
    }

    public function test_health_status_widget_respects_authorization(): void
    {
        $widget = HealthStatusWidget::make()->authorize(false);

        $this->assertFalse($widget->shouldRender());
    }
}
