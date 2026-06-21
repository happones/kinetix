<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class BulkWidget extends Model
{
    protected $table = 'bulk_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class BulkActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('bulk_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
    }

    public function test_bulk_actions_are_serialized(): void
    {
        $data = Table::make(BulkWidget::query())
            ->bulkActions([
                Action::make('delete')->label('Delete')->color('danger'),
                Action::make('export')->label('Export'),
            ])
            ->toData();

        $this->assertCount(2, $data->bulkActions);
        $this->assertSame('delete', $data->bulkActions[0]->name);
        $this->assertSame('export', $data->bulkActions[1]->name);
    }

    public function test_unauthorized_bulk_actions_are_dropped(): void
    {
        $data = Table::make(BulkWidget::query())
            ->bulkActions([
                Action::make('allowed')->authorize(true),
                Action::make('blocked')->authorize(false),
            ])
            ->toData();

        $this->assertCount(1, $data->bulkActions);
        $this->assertSame('allowed', $data->bulkActions[0]->name);
    }
}
