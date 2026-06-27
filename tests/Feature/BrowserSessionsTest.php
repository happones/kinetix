<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Sessions\BrowserSessionManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SessionUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password'];
}

class BrowserSessionsTest extends TestCase
{
    private const CURRENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.sessions.enabled', true);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.table', 'sessions');
        $app['config']->set('auth.providers.users.model', SessionUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    private function user(): SessionUser
    {
        return SessionUser::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => Hash::make('secret')]);
    }

    private function seedSession(string $id, int $userId, string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120', int $ago = 0): void
    {
        DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $userId,
            'ip_address'    => '203.0.113.10',
            'user_agent'    => $ua,
            'payload'       => 'x',
            'last_activity' => now()->subMinutes($ago)->getTimestamp(),
        ]);
    }

    private function requestForSession(string $sessionId): Request
    {
        $store = $this->app['session']->driver();
        $store->setId($sessionId);

        $request = Request::create('/');
        $request->setLaravelSession($store);

        return $request;
    }

    public function test_lists_sessions_and_marks_the_current_device_first(): void
    {
        $user = $this->user();
        $this->seedSession(self::OTHER, $user->id, ago: 30);
        $this->seedSession(self::CURRENT, $user->id, ago: 0);

        $manager  = app(BrowserSessionManager::class);
        $sessions = $manager->for($user, $this->requestForSession(self::CURRENT));

        $this->assertCount(2, $sessions);
        $this->assertTrue($sessions[0]->isCurrentDevice);
        $this->assertSame('Chrome', $sessions[0]->browser);
        $this->assertSame('Windows', $sessions[0]->platform);
        $this->assertSame('desktop', $sessions[0]->device);
    }

    public function test_logout_others_keeps_the_current_session(): void
    {
        $user = $this->user();
        $this->seedSession(self::CURRENT, $user->id);
        $this->seedSession(self::OTHER, $user->id);

        $manager = app(BrowserSessionManager::class);
        $removed = $manager->logoutOthers($user, $this->requestForSession(self::CURRENT));

        $this->assertSame(1, $removed);
        $this->assertDatabaseHas('sessions', ['id' => self::CURRENT]);
        $this->assertDatabaseMissing('sessions', ['id' => self::OTHER]);
    }

    public function test_destroy_others_requires_the_correct_password(): void
    {
        $user = $this->user();
        $this->seedSession(self::OTHER, $user->id);

        $this->actingAs($user)
            ->deleteJson('/_kinetix/sessions/others', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->assertDatabaseHas('sessions', ['id' => self::OTHER]);

        $this->actingAs($user)
            ->deleteJson('/_kinetix/sessions/others', ['password' => 'secret'])
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => self::OTHER]);
    }

    public function test_index_reports_unavailable_without_the_database_driver(): void
    {
        config()->set('session.driver', 'array');
        $user = $this->user();

        $this->actingAs($user)
            ->getJson('/_kinetix/sessions')
            ->assertOk()
            ->assertJsonPath('databaseDriver', false)
            ->assertJsonPath('sessions', []);
    }
}
