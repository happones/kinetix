<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Data\InfolistEntryData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     * Each entry takes one cell unless it sets its own `columnSpan()`; the grid
     * collapses to one column when the item is narrow (a phone).
     */
    public function grid(int $columns): static
    {
        $this->gridColumns = $columns;

        return $this;
    }

    protected function getExtraData(?Model $record = null): array
    {
        // Load the repeated relation explicitly rather than lazily, so the
        // entry also works under Model::preventLazyLoading().
        if ($record !== null && $this->getStateUsing === null && $record->isRelation($this->name)) {
            $record->loadMissing($this->name);
        }

        $items = $this->getRawState($record);

        // Models: load what the sub-entries reach through relations in one
        // query per relation, not one per item.
        if ($items instanceof EloquentCollection && $items->isNotEmpty()) {
            $paths = $this->subEntryRelationPaths($items->first());

            if ($paths !== []) {
                $items->loadMissing($paths);
            }
        }

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
                    $serialized = $data->toArray();

                    // In a grid each entry takes one cell unless it asked for
                    // more — the 'full' default would give every entry a row.
                    if ($this->gridColumns !== null && ! $entry->hasExplicitColumnSpan()) {
                        $serialized['columnSpan'] = 1;
                    }

                    $entries[] = $serialized;
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
     * The relation paths the sub-entries read through (`product.name` →
     * `product`), checked against the item model so an array/JSON attribute
     * reached with a dot is never mistaken for a relation.
     *
     * @return list<string>
     */
    private function subEntryRelationPaths(Model $item): array
    {
        $paths = [];

        foreach ($this->schema as $entry) {
            $segments = explode('.', $entry->getName());
            array_pop($segments);

            $model = $item;
            $valid = [];

            foreach ($segments as $segment) {
                if (! $model->isRelation($segment)) {
                    break;
                }

                $valid[] = $segment;
                $model   = $model->{$segment}()->getRelated();
            }

            if ($valid !== []) {
                $paths[] = implode('.', $valid);
            }
        }

        return array_values(array_unique($paths));
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
