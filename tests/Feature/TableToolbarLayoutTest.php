<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ToolbarBook extends Model
{
    protected $table = 'toolbar_books';

    public $timestamps = false;

    protected $guarded = [];
}

class TableToolbarLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('toolbar_books', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
        });
    }

    private function render(?callable $configure = null): array
    {
        $table = Table::make(ToolbarBook::query())
            ->columns([TextColumn::make('title')]);

        if ($configure !== null) {
            $configure($table);
        }

        return $table->toData()->toArray();
    }

    public function test_the_toolbar_layout_defaults_to_auto(): void
    {
        $this->assertSame('auto', $this->render()['toolbarLayout']);
    }

    public function test_the_toolbar_layout_can_be_pinned(): void
    {
        $data = $this->render(fn (Table $t) => $t->toolbarLayout('inline'));

        $this->assertSame('inline', $data['toolbarLayout']);
    }

    public function test_an_unsupported_layout_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->render(fn (Table $t) => $t->toolbarLayout('floaty'));
    }
}
