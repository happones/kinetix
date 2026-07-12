<?php

declare(strict_types=1);

namespace Happones\Kinetix\Widgets;

/**
 * Drops the existing `<KinetixQueueStats>` live queue-health panel into a
 * `WidgetsGrid` layout. Unlike other widgets, its data isn't carried through
 * `getData()` — the Vue component self-polls via `useKinetixQueue()` exactly
 * as it does when used standalone. This widget only contributes positioning
 * (`columnSpan`, `sort`) and access control (`visible()`/`authorize()`).
 *
 *     QueueStatsWidget::make()->columnSpan(['default' => 12, 'lg' => 6]);
 */
class QueueStatsWidget extends Widget
{
    protected string $type = 'queue-stats';

    protected function getData(): array
    {
        return [];
    }
}
