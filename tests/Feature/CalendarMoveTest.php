<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Calendar\Calendar;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

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
