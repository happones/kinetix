<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Kanban\Kanban;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class KanbanUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class KanbanTask extends Model
{
    protected $table = 'tasks';

    public $timestamps = false;

    protected $guarded = [];
}

class KanbanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('status')->default('todo');
        });

        KanbanTask::create(['title' => 'A', 'status' => 'todo']);
        KanbanTask::create(['title' => 'B', 'status' => 'doing']);
        KanbanTask::create(['title' => 'C', 'status' => 'todo']);
    }

    private function board(): Kanban
    {
        return Kanban::make(KanbanTask::query())
            ->statusColumn('status')
            ->statuses(['todo' => 'To Do', 'doing' => 'In Progress', 'done' => 'Done'])
            ->cardTitle('title');
    }

    public function test_groups_records_into_status_columns(): void
    {
        $data = $this->board()->toData();

        $this->assertCount(3, $data->columns);
        $this->assertSame('todo', $data->columns[0]->key);
        $this->assertCount(2, $data->columns[0]->cards);   // A, C
        $this->assertCount(1, $data->columns[1]->cards);   // B
        $this->assertCount(0, $data->columns[2]->cards);   // none
        $this->assertSame('A', $data->columns[0]->cards[0]->title);
    }

    public function test_move_endpoint_updates_the_status(): void
    {
        $descriptor = $this->board()->toData()->model;
        $task       = KanbanTask::where('title', 'A')->first();

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => $descriptor,
                'recordId' => $task->id,
                'status'   => 'done',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_move_rejects_a_status_outside_the_board(): void
    {
        $descriptor = $this->board()->toData()->model;
        $task       = KanbanTask::where('title', 'A')->first();

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => $descriptor,
                'recordId' => $task->id,
                'status'   => 'archived', // not in the board's statuses
            ])
            ->assertStatus(403);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_move_rejects_an_invalid_signature(): void
    {
        $task = KanbanTask::where('title', 'A')->first();

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => 'not-a-valid-encrypted-descriptor',
                'recordId' => $task->id,
                'status'   => 'done',
            ])
            ->assertStatus(400);

        $this->assertSame('todo', $task->fresh()->status);
    }
}
