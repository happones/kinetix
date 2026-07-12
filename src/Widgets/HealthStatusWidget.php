<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

/**
 * Drops the existing `<KinetixHealthStatus>` live application-health panel
 * into a `WidgetsGrid` layout. Unlike other widgets, its data isn't carried
 * through `getData()` — the Vue component self-polls via
 * `useKinetixHealth()` exactly as it does when used standalone. This widget
 * only contributes positioning (`columnSpan`, `sort`) and access control
 * (`visible()`/`authorize()`).
 *
 *     HealthStatusWidget::make()->columnSpan(['default' => 12, 'lg' => 6]);
 */
class HealthStatusWidget extends Widget
{
    protected string $type = 'health-status';

    protected function getData(): array
    {
        return [];
    }
}
