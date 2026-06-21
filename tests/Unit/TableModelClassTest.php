<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class TblThing extends Model
{
    protected $table = 'tbl_things';

    public $timestamps = false;

    protected $guarded = [];
}

class TableModelClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tbl_things', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('active')->default(false);
        });
    }

    public function test_model_class_resolved_from_builder(): void
    {
        $this->assertSame(TblThing::class, Table::make(TblThing::query())->getModelClass());
    }

    public function test_model_class_resolved_from_model_instance(): void
    {
        $this->assertSame(TblThing::class, Table::make(new TblThing())->getModelClass());
    }

    public function test_model_class_resolved_from_class_string(): void
    {
        $this->assertSame(TblThing::class, Table::make(TblThing::class)->getModelClass());
    }

    public function test_token_carries_model_and_only_editable_columns(): void
    {
        $data = Table::make(TblThing::query())
            ->columns([
                TextColumn::make('name'),      // display only
                ToggleColumn::make('active'),  // editable
            ])
            ->toData();

        $payload = Crypt::decrypt($data->model);

        $this->assertSame(TblThing::class, $payload['model']);
        $this->assertSame(['active'], $payload['columns']);
    }
}
