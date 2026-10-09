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
use Illuminate\Testing\TestResponse;

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
            $table->integer('sort_order')->default(0);
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

    private function reorderableBoard(): Kanban
    {
        return $this->board()->reorderable('sort_order');
    }

    /**
     * @param list<int> $order
     */
    private function move(string $title, string $status, array $order, ?Kanban $board = null): TestResponse
    {
        return $this->actingAs(KanbanUser::firstOrCreate(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/kanban-move', [
                'model'    => ($board ?? $this->reorderableBoard())->toData()->model,
                'recordId' => $this->task($title)->id,
                'status'   => $status,
                'order'    => $order,
            ]);
    }

    private function task(string $title): KanbanTask
    {
        return KanbanTask::where('title', $title)->firstOrFail();
    }

    /**
     * @param array<string, int> $positions title => position
     */
    private function position(array $positions): void
    {
        foreach ($positions as $title => $position) {
            $this->task($title)->update(['sort_order' => $position]);
        }
    }

    public function test_a_reorderable_board_shows_cards_in_their_saved_order(): void
    {
        $this->position(['A' => 2, 'C' => 1]);

        $data = $this->reorderableBoard()->toData();

        $this->assertTrue($data->reorderable);
        $this->assertSame(['C', 'A'], array_map(fn ($card) => $card->title, $data->columns[0]->cards));
        $this->assertFalse($this->board()->toData()->reorderable);
    }

    /**
     * The position A held in "todo" means nothing in "doing": it takes a fresh
     * one after the column's last, and B keeps its own.
     */
    public function test_a_card_dropped_into_another_column_takes_a_fresh_position_there(): void
    {
        $this->position(['A' => 10, 'B' => 20, 'C' => 30]);

        // A lands below B in "doing".
        $this->move('A', 'doing', [$this->task('B')->id, $this->task('A')->id])->assertOk();

        $this->assertSame('doing', $this->task('A')->status);
        $this->assertSame(20, $this->task('B')->sort_order);
        $this->assertSame(21, $this->task('A')->sort_order);
        $this->assertSame(30, $this->task('C')->sort_order);

        // Dropped on top, C takes the free position before B: nothing else moves.
        $this->move('C', 'doing', [$this->task('C')->id, $this->task('B')->id, $this->task('A')->id])->assertOk();

        $this->assertSame(
            ['A' => 21, 'B' => 20, 'C' => 19],
            KanbanTask::orderBy('title')->pluck('sort_order', 'title')->all(),
        );
    }

    /**
     * A drop between two cards lands on a free position between them, so
     * neither is written — not even one this user may not edit. Before, every
     * card listed in the order had to pass the write check.
     */
    public function test_a_drop_between_two_cards_writes_neither(): void
    {
        Gate::policy(KanbanTask::class, KanbanTaskLockedCPolicy::class);
        $this->position(['A' => 10, 'B' => 20, 'C' => 30]);

        // B lands between A and C in "todo"; C is locked.
        $this->move('B', 'todo', [$this->task('A')->id, $this->task('B')->id, $this->task('C')->id])->assertOk();

        $this->assertSame('todo', $this->task('B')->status);
        $this->assertSame(
            ['A' => 10, 'B' => 20, 'C' => 30],
            KanbanTask::orderBy('title')->pluck('sort_order', 'title')->all(),
        );
    }

    /**
     * A board narrower than its move scope (one project's cards): a drop
     * into another column used to renumber the whole column, cards of other
     * projects included — or fail with a 403 when one of them was locked.
     */
    public function test_a_drop_into_a_column_leaves_the_cards_a_narrower_board_hides(): void
    {
        $this->position(['A' => 1, 'B' => 1, 'C' => 2]);
        $hidden = KanbanTask::create(['title' => 'H', 'status' => 'doing', 'sort_order' => 2]);
        Gate::policy(KanbanTask::class, KanbanTaskLockedHPolicy::class);

        $board = Kanban::make(KanbanTask::query()->where('title', '!=', 'H'))
            ->statusColumn('status')
            ->statuses(['todo' => 'To Do', 'doing' => 'In Progress', 'done' => 'Done'])
            ->cardTitle('title')
            ->reorderable('sort_order');

        // A lands under B in "doing".
        $this->move('A', 'doing', [$this->task('B')->id, $this->task('A')->id], $board)->assertOk();

        $this->assertSame('doing', $this->task('A')->status);
        $this->assertSame(1, $this->task('B')->sort_order);
        $this->assertSame(2, $hidden->fresh()->sort_order);
        $this->assertSame(3, $this->task('A')->sort_order);
    }

    public function test_a_card_reorders_within_its_own_column(): void
    {
        $this->position(['A' => 1, 'C' => 2]);

        $this->move('C', 'todo', [$this->task('C')->id, $this->task('A')->id])->assertOk();

        $this->assertSame(1, $this->task('C')->sort_order);
        $this->assertSame(2, $this->task('A')->sort_order);
        $this->assertSame('todo', $this->task('C')->status);
    }

    /**
     * A fresh column of zeros has nothing to trade: the destination column is
     * numbered in the order it was shown (zeros, then by key).
     */
    public function test_an_unnumbered_column_is_numbered_in_the_order_shown(): void
    {
        // B lands between A and C in "todo".
        $this->move('B', 'todo', [$this->task('A')->id, $this->task('B')->id, $this->task('C')->id])->assertOk();

        $this->assertSame('todo', $this->task('B')->status);
        $this->assertSame(
            ['A' => 1, 'B' => 2, 'C' => 3],
            KanbanTask::orderBy('title')->pluck('sort_order', 'title')->all(),
        );
    }

    public function test_cards_outside_the_destination_column_keep_their_positions(): void
    {
        $this->position(['A' => 1, 'B' => 5, 'C' => 2]);

        // B is in "doing": listing it in the todo order changes nothing for it.
        $this->move('A', 'todo', [$this->task('B')->id, $this->task('C')->id, $this->task('A')->id])->assertOk();

        $this->assertSame(5, $this->task('B')->sort_order);
        $this->assertSame('doing', $this->task('B')->status);
        $this->assertSame(1, $this->task('C')->sort_order);
        $this->assertSame(2, $this->task('A')->sort_order);
    }

    public function test_a_refused_order_keeps_the_card_in_its_column(): void
    {
        Gate::policy(KanbanTask::class, KanbanTaskLockedCPolicy::class);

        // Numbering "todo" would rewrite C, which this user may not write.
        $this->move('B', 'todo', [$this->task('B')->id, $this->task('A')->id, $this->task('C')->id])
            ->assertForbidden();

        $this->assertSame('doing', $this->task('B')->status);
        $this->assertSame([0, 0, 0], KanbanTask::orderBy('id')->pluck('sort_order')->all());
    }

    public function test_a_plain_board_ignores_an_order(): void
    {
        $this->position(['A' => 1, 'C' => 2]);

        $this->move('C', 'done', [$this->task('C')->id], $this->board())->assertOk();

        $this->assertSame('done', $this->task('C')->status);
        $this->assertSame(2, $this->task('C')->sort_order);
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

class KanbanTaskLockedCPolicy
{
    public function update(KanbanUser $user, KanbanTask $task): bool
    {
        return $task->title !== 'C';
    }
}

class KanbanTaskLockedHPolicy
{
    public function update(KanbanUser $user, KanbanTask $task): bool
    {
        return $task->title !== 'H';
    }
}
