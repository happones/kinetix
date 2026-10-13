<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Concerns;

use Happones\Kinetix\Data\InfolistEntryData;
use Happones\Kinetix\Infolists\Components\Component;
use Illuminate\Database\Eloquent\Model;

/**
 * Serializes a container's children into its grid. On the 12-column grid an
 * entry without `columnSpan()` keeps the full row, so spans lay it out; in a
 * narrower one — `columns(2)`, `['md' => 3]` — it takes one cell, so the
 * columns asked for are the columns drawn.
 */
trait LaysOutChildren
{
    /**
     * @param  array<int, Component>         $components
     * @param  int|array<string, int>        $columns
     * @return array<int, InfolistEntryData>
     */
    protected function childrenData(array $components, int|array $columns, string $operation, ?Model $record): array
    {
        $widest   = is_array($columns) ? (int) max($columns === [] ? [12] : $columns) : $columns;
        $children = [];

        foreach ($components as $component) {
            $data = $component->toData($operation, $record);

            if ($data === null) {
                continue;
            }

            if ($widest > 1 && $widest < 12 && ! $component->hasExplicitColumnSpan()) {
                $data->columnSpan = 1;
            }

            $children[] = $data;
        }

        return $children;
    }
}
