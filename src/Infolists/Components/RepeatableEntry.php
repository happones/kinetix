<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Repeats a block of entries once per item of a HasMany/array attribute (the
 * read-only counterpart of the Repeater form field). Each item is resolved
 * through the entry sub-schema, so any entry type (Text/Icon/Color/…) can
 * appear inside.
 *
 *     RepeatableEntry::make('items')
 *         ->schema([
 *             TextEntry::make('name'),
 *             TextEntry::make('qty'),
 *         ]);
 */
class RepeatableEntry extends Entry
{
    /**
     * @var array<int, Entry>
     */
    protected array $schema = [];

    protected ?int $gridColumns = null;

    protected function getType(): string
    {
        return 'repeatable';
    }

    /**
     * @param array<int, Entry> $schema
     */
    public function schema(array $schema): static
    {
        $this->schema = array_values($schema);

        return $this;
    }

    /**
     * Lay each item's entries out in a grid of N columns (default: stacked).
     */
    public function grid(int $columns): static
    {
        $this->gridColumns = $columns;

        return $this;
    }

    protected function getExtraData(?Model $record = null): array
    {
        $items = $this->getRawState($record);

        if ($items instanceof Collection) {
            $items = $items->all();
        }

        if (! is_array($items)) {
            $items = [];
        }

        // Serialize each item THROUGH the sub-schema, so an entry's state/
        // format/icon/color closures run against the item model (or array row).
        $rows = [];

        foreach ($items as $item) {
            $model = $item instanceof Model ? $item : null;

            $entries = [];

            foreach ($this->schema as $entry) {
                $data = $model !== null
                    ? $entry->toData('view', $model)
                    : $this->arrayEntryData($entry, is_array($item) ? $item : []);

                if ($data !== null) {
                    $entries[] = $data->toArray();
                }
            }

            $rows[] = $entries;
        }

        return [
            'repeatableItems' => $rows,
            'gridColumns'     => $this->gridColumns,
        ];
    }

    /**
     * Serialize one entry against an ARRAY row (when the repeated attribute is
     * a JSON/array cast rather than a relationship of models).
     *
     * @param array<string, mixed> $row
     */
    private function arrayEntryData(Entry $entry, array $row): ?InfolistEntryData
    {
        // Wrap the array row in a lightweight model so the entry's data_get()
        // name resolution ('field', dot-notation) works uniformly.
        $wrapper = new class extends Model
        {
            protected $guarded = [];
        };
        $wrapper->setRawAttributes($row, true);

        return $entry->toData('view', $wrapper);
    }
}
