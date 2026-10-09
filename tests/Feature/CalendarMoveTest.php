<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Calendar\Calendar;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;

class CalendarMoveUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class CalendarMoveEvent extends Model
{
    protected $table = 'events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}

class CalendarMoveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
        });

        CalendarMoveEvent::create([
            'name'      => 'Kickoff',
            'starts_at' => '2026-06-15 09:00:00',
            'ends_at'   => '2026-06-15 10:30:00',
        ]);
    }

    private function calendar(): Calendar
    {
        return Calendar::make(CalendarMoveEvent::query())
            ->dateColumn('starts_at')
            ->endColumn('ends_at')
            ->title('name')
            ->timezone('UTC')
            ->moveable();
    }

    public function test_the_descriptor_ships_only_when_moveable(): void
    {
        $readOnly = Calendar::make(CalendarMoveEvent::query())
            ->dateColumn('starts_at')
            ->title('name')
            ->toData();

        $this->assertNull($readOnly->model);
        $this->assertNotNull($this->calendar()->toData()->model);
    }

    public function test_move_endpoint_updates_the_start_and_shifts_the_end(): void
    {
        $descriptor = $this->calendar()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $fresh = $event->fresh();
        $this->assertSame('2026-06-18 14:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        // The end shifted by the same delta — the 90-minute duration survives.
        $this->assertSame('2026-06-18 15:30:00', $fresh->ends_at->format('Y-m-d H:i:s'));
    }

    /**
     * The browser moves an all-day event by whole days on the calendar's wall
     * clock. Shifting the end by elapsed seconds left it an hour off once a
     * DST change sat between the two starts, and it read back as a timed
     * event.
     */
    public function test_an_all_day_event_moved_across_a_dst_change_still_ends_at_midnight(): void
    {
        // 8 March 2026, the day New York springs forward, stored in UTC.
        $event = CalendarMoveEvent::create([
            'name'      => 'Offsite',
            'starts_at' => '2026-03-08 05:00:00',
            'ends_at'   => '2026-03-09 04:00:00',
        ]);
        $descriptor = $this->calendar()->timezone('America/New_York')->toData()->model;

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-03-09T04:00:00Z', // 9 March, 00:00 EDT
            ])
            ->assertOk();

        $this->assertSame('2026-03-10 04:00:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));

        $moved = collect($this->calendar()->timezone('America/New_York')->toData()->events)
            ->firstWhere('id', $event->id);
        $this->assertTrue($moved->allDay);
    }

    public function test_move_rejects_an_invalid_signature(): void
    {
        $event = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => 'not-a-valid-encrypted-descriptor',
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertStatus(400);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_move_rejects_an_unparseable_start(): void
    {
        $descriptor = $this->calendar()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => 'not-a-date',
            ])
            ->assertStatus(422);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_move_scope_hides_records_outside_the_calendars_constraints(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('team_id')->default(1);
        });
        $event = CalendarMoveEvent::firstOrFail();
        $event->update(['team_id' => 2]);

        $descriptor = $this->calendar()->moveScope(['team_id' => 1])->toData()->model;

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertStatus(404);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_move_is_authorized_through_the_models_update_policy(): void
    {
        Gate::policy(CalendarMoveEvent::class, CalendarMoveDenyPolicy::class);

        $descriptor = $this->calendar()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertStatus(403);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_authorize_move_checks_the_named_ability(): void
    {
        Gate::policy(CalendarMoveEvent::class, CalendarMoveDenyPolicy::class);

        // The policy denies update but allows reschedule.
        $descriptor = $this->calendar()->authorizeMove('reschedule')->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertOk();

        $this->assertSame('2026-06-18 14:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_move_rejects_a_missing_start_instead_of_moving_to_now(): void
    {
        $descriptor = $this->calendar()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
            ])
            ->assertStatus(422);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_the_data_says_which_writes_the_calendar_allows(): void
    {
        $moveOnly   = $this->calendar()->toData();
        $resizeOnly = $this->calendar()->moveable(false)->resizable()->toData();
        $readOnly   = $this->calendar()->moveable(false)->toData();

        $this->assertTrue($moveOnly->moveable);
        $this->assertFalse($moveOnly->resizable);

        // Resizing alone still needs the descriptor, but nothing moves.
        $this->assertNotNull($resizeOnly->model);
        $this->assertFalse($resizeOnly->moveable);
        $this->assertTrue($resizeOnly->resizable);

        $this->assertNull($readOnly->model);
    }

    public function test_resizable_needs_an_end_column(): void
    {
        $this->expectException(LogicException::class);

        Calendar::make(CalendarMoveEvent::query())
            ->dateColumn('starts_at')
            ->resizable()
            ->toData();
    }

    public function test_resize_endpoint_rewrites_only_the_end(): void
    {
        $descriptor = $this->calendar()->resizable()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'end'      => '2026-06-15T12:15:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $fresh = $event->fresh();
        $this->assertSame('2026-06-15 09:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 12:15:00', $fresh->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_resize_may_end_an_event_on_a_later_day(): void
    {
        $descriptor = $this->calendar()->resizable()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'end'      => '2026-06-17T10:30:00+00:00',
            ])
            ->assertOk();

        $this->assertSame('2026-06-17 10:30:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_resize_rejects_an_end_before_the_start(): void
    {
        $descriptor = $this->calendar()->resizable()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'end'      => '2026-06-15T08:00:00Z',
            ])
            ->assertStatus(422);

        $this->assertSame('2026-06-15 10:30:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_resize_rejects_an_unparseable_end(): void
    {
        $descriptor = $this->calendar()->resizable()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'end'      => 'not-a-date',
            ])
            ->assertStatus(422);

        $this->assertSame('2026-06-15 10:30:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_a_moveable_only_descriptor_cannot_resize(): void
    {
        $descriptor = $this->calendar()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'end'      => '2026-06-15T12:00:00Z',
            ])
            ->assertStatus(403);

        $this->assertSame('2026-06-15 10:30:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_a_resizable_only_descriptor_cannot_move(): void
    {
        $descriptor = $this->calendar()->moveable(false)->resizable()->toData()->model;
        $event      = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Ada']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertStatus(403);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_a_descriptor_minted_before_resizing_existed_still_moves(): void
    {
        $user = CalendarMoveUser::create(['name' => 'Ada']);
        $this->actingAs($user);

        // The shape moveable() calendars minted before the move/resize flags.
        $descriptor = SignedDescriptor::seal([
            'model'       => CalendarMoveEvent::class,
            'dateColumn'  => 'starts_at',
            'endColumn'   => 'ends_at',
            'moveAbility' => null,
            'moveScope'   => [],
        ]);
        $event = CalendarMoveEvent::firstOrFail();

        $this->postJson('/_kinetix/tables/calendar-move', [
            'model'    => $descriptor,
            'recordId' => $event->id,
            'start'    => '2026-06-18T14:00:00Z',
        ])->assertOk();

        $this->postJson('/_kinetix/tables/calendar-resize', [
            'model'    => $descriptor,
            'recordId' => $event->id,
            'end'      => '2026-06-18T18:00:00Z',
        ])->assertStatus(403);

        $fresh = $event->fresh();
        $this->assertSame('2026-06-18 14:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-18 15:30:00', $fresh->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_resize_is_authorized_and_scoped_like_a_move(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('team_id')->default(1);
        });
        Gate::policy(CalendarMoveEvent::class, CalendarMoveDenyPolicy::class);

        $event = CalendarMoveEvent::firstOrFail();
        $user  = CalendarMoveUser::create(['name' => 'Ada']);

        // The policy denies update.
        $this->actingAs($user)
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $this->calendar()->resizable()->toData()->model,
                'recordId' => $event->id,
                'end'      => '2026-06-15T12:00:00Z',
            ])
            ->assertStatus(403);

        // Outside the scope, the record doesn't exist.
        $this->actingAs($user)
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $this->calendar()->resizable()->authorizeMove('reschedule')->moveScope(['team_id' => 2])->toData()->model,
                'recordId' => $event->id,
                'end'      => '2026-06-15T12:00:00Z',
            ])
            ->assertStatus(404);

        // The named ability, inside the scope.
        $this->actingAs($user)
            ->postJson('/_kinetix/tables/calendar-resize', [
                'model'    => $this->calendar()->resizable()->authorizeMove('reschedule')->moveScope(['team_id' => 1])->toData()->model,
                'recordId' => $event->id,
                'end'      => '2026-06-15T12:00:00Z',
            ])
            ->assertOk();

        $this->assertSame('2026-06-15 12:00:00', $event->fresh()->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_a_descriptor_minted_for_another_user_is_rejected(): void
    {
        $minter = CalendarMoveUser::create(['name' => 'Ada']);
        $this->actingAs($minter);
        $descriptor = $this->calendar()->toData()->model;
        auth()->logout();

        $event = CalendarMoveEvent::firstOrFail();

        $this->actingAs(CalendarMoveUser::create(['name' => 'Eve']))
            ->postJson('/_kinetix/tables/calendar-move', [
                'model'    => $descriptor,
                'recordId' => $event->id,
                'start'    => '2026-06-18T14:00:00Z',
            ])
            ->assertStatus(403);

        $this->assertSame('2026-06-15 09:00:00', $event->fresh()->starts_at->format('Y-m-d H:i:s'));
    }
}

class CalendarMoveDenyPolicy
{
    public function update(CalendarMoveUser $user, CalendarMoveEvent $event): bool
    {
        return false;
    }

    public function reschedule(CalendarMoveUser $user, CalendarMoveEvent $event): bool
    {
        return true;
    }
}
