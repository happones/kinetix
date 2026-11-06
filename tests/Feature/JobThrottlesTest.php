<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Gdpr\Jobs\GdprExportJob;
use Happones\Kinetix\Gdpr\KinetixGdpr;
use Happones\Kinetix\Support\KinetixThrottle;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class JtUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class JtRecord extends Model
{
    protected $table = 'jt_records';

    public $timestamps = false;
}

class JtExporter extends Exporter
{
    protected static ?string $model = JtRecord::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }
}

/**
 * Endpoints that queue jobs, send mail or call a provider are throttled per
 * user, each family with its own configurable budget.
 */
class JobThrottlesTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', JtUser::class);
        $app['config']->set('kinetix.gdpr.enabled', true);
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.reports_center.enabled', true);
        $app['config']->set('kinetix.channels.enabled', true);
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

        Schema::create('jt_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        KinetixGdpr::export('profile', fn (JtUser $user) => ['name' => $user->name]);
    }

    public function test_starting_exports_is_throttled(): void
    {
        Queue::fake();
        config()->set('kinetix.exports.start_throttle', '3,1');
        $user = JtUser::create(['name' => 'Ada']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.exports.start'), ['exporter' => JtExporter::token()])->assertOk();
        }

        $this->actingAs($user)->postJson(route('kinetix.exports.start'), ['exporter' => JtExporter::token()])
            ->assertStatus(429)
            ->assertJsonPath('code', 'throttled')
            ->assertJsonMissingPath('errors');

        Queue::assertPushedTimes(ExportProcessor::class, 3);
    }

    public function test_gdpr_exports_are_throttled(): void
    {
        Queue::fake();
        $user = JtUser::create(['name' => 'Ada']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.gdpr.export'))->assertOk();
        }

        $this->actingAs($user)->postJson(route('kinetix.gdpr.export'))->assertStatus(429);

        Queue::assertPushedTimes(GdprExportJob::class, 3);
    }

    public function test_a_limit_set_to_false_never_answers_429(): void
    {
        Queue::fake();
        config()->set('kinetix.gdpr.export_throttle', false);
        $user = JtUser::create(['name' => 'Ada']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)->postJson(route('kinetix.gdpr.export'))->assertOk();
        }

        Queue::assertPushedTimes(GdprExportJob::class, 6);
    }

    public function test_every_job_route_names_its_limiter(): void
    {
        foreach ([
            'kinetix.exports.start'            => 'kinetix-exports',
            'kinetix.gdpr.export'              => 'kinetix-gdpr-export',
            'kinetix.report-runs.launch'       => 'kinetix-report-runs',
            'kinetix.report-runs.retry'        => 'kinetix-report-runs',
            'kinetix.report-schedules.run-now' => 'kinetix-report-runs',
            'kinetix.members.resend'           => 'kinetix-member-resend',
            'kinetix.members.store'            => 'kinetix-member-invite',
            'kinetix.channels.link'            => 'kinetix-channels',
            'kinetix.channels.test'            => 'kinetix-channels',
        ] as $route => $limiter) {
            $this->assertContains(
                KinetixThrottle::middleware($limiter),
                Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [],
                "{$route} is not throttled by {$limiter}.",
            );
        }
    }

    public function test_older_inline_limits_moved_to_named_limiters(): void
    {
        foreach ([
            'kinetix-spotlight'     => ['spotlight.throttle', [60, 1]],
            'kinetix-webhook-test'  => ['webhooks.test_throttle', [6, 1]],
            'kinetix-flow-simulate' => ['flows.simulate_throttle', [10, 1]],
            'kinetix-recompute'     => ['forms.recompute_throttle', [120, 1]],
        ] as $limiter => [$key, $default]) {
            $this->assertSame($key, KinetixThrottle::LIMITERS[$limiter][0]);
            $this->assertSame($default, KinetixThrottle::parse(config("kinetix.{$key}"), KinetixThrottle::LIMITERS[$limiter][1]));
        }

        // The older `attempts,minutes,prefix` form still parses; the prefix
        // is moot (each family counts on its own limiter).
        $this->assertSame([30, 2], KinetixThrottle::parse('30,2,my-prefix', '9,9'));
        $this->assertSame([30, 2], KinetixThrottle::strictParse('30,2,my-prefix'));
    }

    public function test_a_throttled_recompute_answers_the_localized_shape(): void
    {
        config()->set('kinetix.forms.recompute_throttle', '1,1');
        $user = JtUser::create(['name' => 'Ada']);

        $this->actingAs($user)->postJson(route('kinetix.forms.recompute'), ['descriptor' => 'x']);

        $response = $this->actingAs($user)->postJson(route('kinetix.forms.recompute'), ['descriptor' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'throttled')
            ->assertHeader('Retry-After');

        $this->assertStringStartsWith('Too many attempts.', (string) $response->json('message'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

    public function test_families_count_separately(): void
    {
        Queue::fake();
        config()->set('kinetix.forms.recompute_throttle', '1,1');
        config()->set('kinetix.gdpr.export_throttle', '1,1');
        $user = JtUser::create(['name' => 'Ada']);

        // Exhaust the recompute budget...
        $this->actingAs($user)->postJson(route('kinetix.forms.recompute'), ['descriptor' => 'x']);
        $this->actingAs($user)->postJson(route('kinetix.forms.recompute'), ['descriptor' => 'x'])->assertStatus(429);

        // ...the export budget is untouched, and has its own limit.
        $this->actingAs($user)->postJson(route('kinetix.gdpr.export'))->assertOk();
        $this->actingAs($user)->postJson(route('kinetix.gdpr.export'))->assertStatus(429);
        $this->actingAs($user)->postJson(route('kinetix.forms.recompute'), ['descriptor' => 'x'])->assertStatus(429);
    }

    public function test_a_disabled_limit_is_unlimited(): void
    {
        config()->set('kinetix.forms.recompute_throttle', false);
        $user = JtUser::create(['name' => 'Ada']);

        for ($attempt = 1; $attempt <= 130; $attempt++) {
            $this->assertNotSame(429, $this->actingAs($user)->postJson(route('kinetix.forms.recompute'), ['descriptor' => 'x'])->status());
        }

        $this->assertSame(Unlimited::class, KinetixThrottle::limitFor('kinetix-recompute', request())::class);
    }

    public function test_every_limiter_is_registered(): void
    {
        foreach (array_keys(KinetixThrottle::LIMITERS) as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), "{$name} is not registered.");
        }
    }

    public function test_the_limit_parser(): void
    {
        $this->assertSame([5, 1], KinetixThrottle::parse('5,1', '9,9'));
        $this->assertSame([3, 60], KinetixThrottle::parse(' 3 , 60 ', '9,9'));
        $this->assertSame([7, 1], KinetixThrottle::parse('7', '9,9'));
        $this->assertSame([9, 9], KinetixThrottle::parse('nope', '9,9'));
        $this->assertSame([9, 9], KinetixThrottle::parse('0,1', '9,9'));
        $this->assertNull(KinetixThrottle::parse(false, '9,9'));
        $this->assertNull(KinetixThrottle::parse(null, '9,9'));
        $this->assertNull(KinetixThrottle::parse('', '9,9'));
    }
}
