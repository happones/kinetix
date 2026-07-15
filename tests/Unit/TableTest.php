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

    public function test_pagination_serializes_from_and_to(): void
    {
        // Regression: the frontend "Showing :from to :to of :total" line read
        // pagination.from / pagination.to, which the DTO did not send.
        TblItem::create(['name' => 'a']);
        TblItem::create(['name' => 'b']);
        TblItem::create(['name' => 'c']);

        $data = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->toArray();

        $this->assertSame(3, $data['pagination']['total']);
        $this->assertSame(1, $data['pagination']['from']);
        $this->assertSame(3, $data['pagination']['to']);
    }

    public function test_sticky_actions_flag_serializes(): void
    {
        $default = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->toArray();
        $this->assertFalse($default['stickyActions']);

        $sticky = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->stickyActions()
            ->toArray();
        $this->assertTrue($sticky['stickyActions']);
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

    public function test_client_side_ships_all_rows_without_server_pagination(): void
    {
        foreach (range(1, 15) as $i) {
            TblItem::create(['name' => "item {$i}"]);
        }

        // Server-driven (default): page 1 of 10.
        $server = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->toArray();
        $this->assertCount(10, $server['records']);
        $this->assertSame(15, $server['pagination']['total']);
        $this->assertFalse($server['clientSide']);

        // Client-side: all rows, no server pagination meta.
        $client = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->clientSide()
            ->toArray();
        $this->assertCount(15, $client['records']);
        $this->assertNull($client['pagination']);
        $this->assertTrue($client['clientSide']);
    }

    public function test_client_side_respects_the_row_cap(): void
    {
        foreach (range(1, 10) as $i) {
            TblItem::create(['name' => "item {$i}"]);
        }

        $data = Table::make(TblItem::query())
            ->columns([TextColumn::make('name')])
            ->clientSide(max: 3)
            ->toArray();

        $this->assertCount(3, $data['records']);
    }
}
