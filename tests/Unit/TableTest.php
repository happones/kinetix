<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TblItem extends Model
{
    protected $table = 'tbl_items';

    public $timestamps = false;

    protected $guarded = [];
}

class TableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbl_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
    }

    public function test_table_without_heading_serializes(): void
    {
        // Regression: TableData::$heading was non-nullable and threw a TypeError.
        $data = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->toArray();

        $this->assertNull($data['heading']);
        $this->assertSame('', $data['queryPrefix']);
    }

    public function test_query_prefix_is_serialized(): void
    {
        $data = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->queryPrefix('posts_')
            ->toArray();

        $this->assertSame('posts_', $data['queryPrefix']);
    }

    public function test_string_poll_serializes(): void
    {
        // Regression: TableData::$poll was ?int but the setter accepts a string.
        $data = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->poll('5s')
            ->toArray();

        $this->assertSame('5s', $data['poll']);
    }
}
