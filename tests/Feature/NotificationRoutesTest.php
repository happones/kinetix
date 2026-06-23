<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NotifiableUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class NotificationRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedNotification(NotifiableUser $user, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();

        $user->notifications()->create([
            'id'   => $id,
            'type' => 'kinetix',
            'data' => ['title' => 'Export ready'],
        ]);

        return $id;
    }

    public function test_delete_route_removes_the_notification(): void
    {
        $user = NotifiableUser::create(['name' => 'A']);
        $id   = $this->seedNotification($user);

        $this->assertDatabaseHas('notifications', ['id' => $id]);

        $this->actingAs($user)
            ->deleteJson("/_kinetix/notifications/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_read_route_marks_the_notification_as_read(): void
    {
        $user = NotifiableUser::create(['name' => 'A']);
        $id   = $this->seedNotification($user);

        $this->actingAs($user)
            ->postJson("/_kinetix/notifications/{$id}/read")
            ->assertOk();

        $this->assertNotNull($user->fresh()->notifications()->find($id)->read_at);
    }

    public function test_clear_all_route_removes_every_notification(): void
    {
        $user = NotifiableUser::create(['name' => 'A']);
        $this->seedNotification($user);
        $this->seedNotification($user);

        $this->actingAs($user)
            ->deleteJson('/_kinetix/notifications/clear-all')
            ->assertOk();

        $this->assertSame(0, $user->fresh()->notifications()->count());
    }

    public function test_delete_route_resolves_the_id_by_name_with_teams_enabled(): void
    {
        // With teams the prefix gains a leading `{current_team}` param. The id
        // must be resolved by NAME, not positionally, or the team value leaks
        // into `where('id', …)` and nothing is deleted.
        config()->set('kinetix.teams', true);
        $this->app->register(KinetixServiceProvider::class, true);

        $user = NotifiableUser::create(['name' => 'A']);
        $id   = $this->seedNotification($user);

        $this->actingAs($user)
            ->deleteJson("/acme/_kinetix/notifications/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }
}
