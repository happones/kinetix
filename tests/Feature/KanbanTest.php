<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Kanban\Kanban;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

enum KanbanPhase: string
{
    case Todo  = 'todo';
    case Doing = 'doing';
    case Done  = 'done';
}

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

class KanbanEnumTask extends Model
{
    protected $table = 'tasks';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['status' => KanbanPhase::class];
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

    public function test_a_backed_enum_status_cast_groups_and_moves(): void
    {
        $board = Kanban::make(KanbanEnumTask::query())
            ->statusColumn('status')
            ->statuses(['todo' => 'To Do', 'doing' => 'In Progress', 'done' => 'Done'])
            ->cardTitle('title');

        // Grouping stringifies the enum via its backing value (no crash).
        $data = $board->toData();
        $this->assertCount(2, $data->columns[0]->cards);   // A, C
        $this->assertCount(1, $data->columns[1]->cards);   // B

        // Moving casts the plain status string back into the enum.
        $task = KanbanEnumTask::where('title', 'A')->first();

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => $data->model,
                'recordId' => $task->id,
                'status'   => 'done',
            ])
            ->assertOk();

        $this->assertSame(KanbanPhase::Done, $task->fresh()->status);
    }

    public function test_move_scope_hides_records_outside_the_boards_constraints(): void
    {
        // Board bound to team 1 — the task belongs to team 2.
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('team_id')->default(1);
        });
        $task = KanbanTask::where('title', 'A')->first();
        $task->update(['team_id' => 2]);

        $descriptor = $this->board()->moveScope(['team_id' => 1])->toData()->model;

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => $descriptor,
                'recordId' => $task->id,
                'status'   => 'done',
            ])
            ->assertStatus(404);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_move_is_authorized_through_the_models_update_policy(): void
    {
        // A registered policy is enforced automatically (default ability: update).
        Gate::policy(KanbanTask::class, KanbanTaskDenyPolicy::class);

        $descriptor = $this->board()->toData()->model;
        $task       = KanbanTask::where('title', 'A')->first();

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => $descriptor,
                'recordId' => $task->id,
                'status'   => 'done',
            ])
            ->assertStatus(403);

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_authorize_move_checks_the_named_ability(): void
    {
        Gate::policy(KanbanTask::class, KanbanTaskDenyPolicy::class);

        // The policy denies update but allows moveCard.
        $descriptor = $this->board()->authorizeMove('moveCard')->toData()->model;
        $task       = KanbanTask::where('title', 'A')->first();

        $this->actingAs(KanbanUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => $descriptor,
                'recordId' => $task->id,
                'status'   => 'done',
            ])
            ->assertOk();

        $this->assertSame('done', $task->fresh()->status);
    }
}

class KanbanTaskDenyPolicy
{
    public function update(KanbanUser $user, KanbanTask $task): bool
    {
        return false;
    }

    public function moveCard(KanbanUser $user, KanbanTask $task): bool
    {
        return true;
    }
}
