<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A Repeater rendered as a spreadsheet-style table: each item is a row, each
 * sub-field a column. Supports footer summaries and CSV export like a Table.
 *
 * By default rows live in the form state and are saved with the parent form
 * (deferred — one write, no unnecessary saves). Opt into per-row autosave with
 * `->relationship('items')->autosave()`: every add/edit/delete persists straight
 * to the bound Eloquent relation through a signed-descriptor endpoint (only the
 * declared columns are writable, mirroring the table cell-update guard).
 *
 *     TableRepeater::make('items')
 *         ->columns([
 *             TextInput::make('name')->required(),
 *             NumberField::make('qty'),
 *             NumberField::make('price'),
 *         ])
 *         ->summarize(['qty' => 'sum', 'price' => 'sum'])
 *         ->exportable();
 */
class TableRepeater extends Repeater
{
    protected ?string $relationship = null;

    protected bool $autosave = false;

    protected bool $exportable = false;

    /**
     * Column name => aggregate ('sum' | 'avg' | 'count' | 'min' | 'max').
     *
     * @var array<string, string>
     */
    protected array $summarize = [];

    protected function getType(): string
    {
        return 'table-repeater';
    }

    /**
     * Alias of {@see Repeater::schema()} — the columns repeated per row.
     *
     * @param array<int, Component> $components
     */
    public function columns(array $components): static
    {
        return $this->schema($components);
    }

    /**
     * Bind the rows to an Eloquent relation on the form's record. Required for
     * {@see autosave()}; the relation is where new/edited/deleted rows are written.
     */
    public function relationship(string $name): static
    {
        $this->relationship = $name;

        return $this;
    }

    /**
     * Persist each row change to the DB immediately (requires a relationship).
     * When off (default), rows are saved with the parent form submit.
     */
    public function autosave(bool $autosave = true): static
    {
        $this->autosave = $autosave;

        return $this;
    }

    /**
     * Footer aggregates per column: name => 'sum' | 'avg' | 'count' | 'min' | 'max'.
     *
     * @param array<string, string> $summarize
     */
    public function summarize(array $summarize): static
    {
        $this->summarize = $summarize;

        return $this;
    }

    /**
     * Show a button that exports the current rows to CSV.
     */
    public function exportable(bool $exportable = true): static
    {
        $this->exportable = $exportable;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->summarize  = $this->summarize === [] ? null : $this->summarize;
        $data->exportable = $this->exportable;
        $data->autosave   = $this->autosave;

        // A write token is only minted for a render that can actually write: not
        // for `view`, and not for a disabled field. The token IS the authority the
        // autosave endpoint trusts, so handing one to a read-only render would let
        // the row be edited anyway.
        $isWritable = $operation !== 'view' && ! $data->isDisabled;

        if ($isWritable && $this->autosave && $this->relationship !== null && $record !== null && $record->exists) {
            $columns = array_values(array_filter(array_map(
                static fn (FormFieldData $field): ?string => $field->name,
                $data->schema ?? [],
            )));

            $ttl = config('kinetix.tables.token_ttl', 1440);

            $data->autosaveToken = Crypt::encrypt([
                'parent'   => $record::class,
                'key'      => $record->getKey(),
                'relation' => $this->relationship,
                'columns'  => $columns,
                'user'     => auth()->id(),
                'expires'  => is_numeric($ttl) && (int) $ttl > 0
                    ? now()->getTimestamp() + ((int) $ttl * 60)
                    : null,
            ]);
        }

        return $data;
    }
}
