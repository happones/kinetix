<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Infolists\Components\KeyValueEntry;
use Happones\Kinetix\Infolists\Components\RepeatableEntry;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class KvRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'lines' => 'array'];
}

/**
 * KeyValueEntry and RepeatableEntry — the read-only infolist counterparts of
 * the KeyValue and Repeater form fields.
 */
class InfolistKeyValueRepeatableTest extends TestCase
{
    public function test_key_value_entry_serializes_pairs_and_labels(): void
    {
        $record = (new KvRecord)->forceFill([
            'meta' => ['color' => 'red', 'size' => 'L', 'tags' => ['a', 'b']],
        ]);

        $data = KeyValueEntry::make('meta')
            ->keyLabel('Property')
            ->valueLabel('Value')
            ->toData('view', $record);

        $this->assertSame('key-value', $data->type);
        $this->assertSame('Property', $data->keyLabel);
        $this->assertSame('Value', $data->valueLabel);
        // Scalars pass through; nested values are JSON-encoded.
        $this->assertSame('red', $data->state['color']);
        $this->assertSame('["a","b"]', $data->state['tags']);
    }

    public function test_repeatable_entry_serializes_one_block_per_item(): void
    {
        $record = (new KvRecord)->forceFill([
            'lines' => [
                ['name' => 'Widget', 'qty' => 2],
                ['name' => 'Gadget', 'qty' => 5],
            ],
        ]);

        $data = RepeatableEntry::make('lines')
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('qty'),
            ])
            ->grid(2)
            ->toData('view', $record);

        $this->assertSame('repeatable', $data->type);
        $this->assertSame(2, $data->gridColumns);
        $this->assertCount(2, $data->repeatableItems);

        // First item's entries resolved against the array row.
        $first = $data->repeatableItems[0];
        $this->assertSame('name', $first[0]['name']);
        $this->assertSame('Widget', $first[0]['state']);
        $this->assertSame('qty', $first[1]['name']);
        $this->assertSame(2, $first[1]['state']);
    }

    public function test_repeatable_entry_handles_an_empty_set(): void
    {
        $record = (new KvRecord)->forceFill(['lines' => []]);

        $data = RepeatableEntry::make('lines')
            ->schema([TextEntry::make('name')])
            ->toData('view', $record);

        $this->assertSame([], $data->repeatableItems);
    }
}
