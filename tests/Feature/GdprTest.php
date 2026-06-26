<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Gdpr\GdprManager;
use Happones\Kinetix\Gdpr\Jobs\GdprExportJob;
use Happones\Kinetix\Gdpr\KinetixGdpr;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class GdprUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password'];
}

class GdprTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.gdpr.enabled', true);
        $app['config']->set('kinetix.gdpr.require_password', true);
        $app['config']->set('kinetix.gdpr.deletion', 'anonymize');
        $app['config']->set('kinetix.gdpr.anonymize', [
            'name'  => 'Deleted user',
            'email' => null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });

        KinetixGdpr::export('profile', fn (GdprUser $user) => [
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }

    private function user(): GdprUser
    {
        return GdprUser::create([
            'name'     => 'Ada',
            'email'    => 'ada@example.com',
            'password' => Hash::make('secret-password'),
        ]);
    }

    public function test_export_endpoint_dispatches_the_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user())
            ->postJson('/_kinetix/gdpr/export')
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(GdprExportJob::class);
    }

    public function test_manager_collects_registered_sections(): void
    {
        $data = app(GdprManager::class)->collect($this->user());

        $this->assertSame('Ada', $data['profile']['name']);
        $this->assertSame('ada@example.com', $data['profile']['email']);
    }

    public function test_delete_requires_correct_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/gdpr/delete', ['password' => 'wrong'])
            ->assertStatus(422);

        // Still present and not anonymized.
        $this->assertSame('Ada', $user->fresh()->name);
    }

    public function test_delete_anonymizes_the_account(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/gdpr/delete', ['password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $fresh = $user->fresh();
        $this->assertSame('Deleted user', $fresh->name);
        $this->assertNull($fresh->email);
    }

    public function test_delete_mode_hard_deletes_when_configured(): void
    {
        config()->set('kinetix.gdpr.deletion', 'delete');

        $user = $this->user();
        $id   = $user->getKey();

        $this->actingAs($user)
            ->postJson('/_kinetix/gdpr/delete', ['password' => 'secret-password'])
            ->assertOk();

        $this->assertNull(GdprUser::find($id));
    }

    public function test_custom_delete_handler_takes_over(): void
    {
        KinetixGdpr::deleteUsing(function (GdprUser $user) {
            $user->update(['name' => 'CUSTOM']);
        });

        $user = $this->user();

        app(GdprManager::class)->purge($user);

        $this->assertSame('CUSTOM', $user->fresh()->name);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/_kinetix/gdpr/export')->assertStatus(401);
    }

    public function test_export_job_writes_a_json_file_to_the_disk(): void
    {
        Storage::fake('public');

        $user = $this->user();
        (new GdprExportJob(GdprUser::class, $user->getKey()))->handle();

        $files = Storage::disk('public')->files('kinetix-exports');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.json', $files[0]);

        $contents = Storage::disk('public')->get($files[0]);
        $this->assertStringContainsString('ada@example.com', (string) $contents);
    }
}
