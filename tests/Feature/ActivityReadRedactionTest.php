<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Activity\Activity;
use Happones\Kinetix\Activity\ActivityRedactor;
use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class ArrUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ArrAccount extends Model
{
    protected $table = 'arr_accounts';

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'secret'      => 'encrypted',
            'national_id' => ConfidentialCast::class,
        ];
    }
}

/**
 * Rows written before activity redaction existed still hold secrets; the
 * feed (and every other reader of ActivityData) redacts them on read, and
 * `kinetix:activity:redact` rewrites them at rest.
 */
class ActivityReadRedactionTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.activity.enabled', true);
        $app['config']->set('kinetix.activity.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        ActivityRedactor::flush();
        Gate::define('activity.view', fn () => true);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('kinetix_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('log_name')->nullable();
            $table->string('event')->nullable();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        ActivityRedactor::flush();

        parent::tearDown();
    }

    public function test_a_pre_redaction_row_shows_no_secret_in_the_feed(): void
    {
        $this->legacyRow(ArrAccount::class);

        $response = $this->actingAs(ArrUser::create([]))->getJson('/_kinetix/activity')->assertOk();

        $this->assertSame([
            'name'        => 'Acme',
            'api_token'   => '[redacted]',
            'secret'      => '[redacted]',
            'national_id' => '[redacted]',
            'password'    => '[redacted]',
        ], $response->json('data.0.changes.attributes'));
        $this->assertSame(['name' => 'Old', 'secret' => '[redacted]'], $response->json('data.0.changes.old'));
        $this->assertStringNotContainsString('tok_live', $response->getContent());
    }

    public function test_the_php_query_redacts_too(): void
    {
        $this->legacyRow(ArrAccount::class);

        $activity = KinetixActivity::logger()->query()['data'][0];

        $this->assertSame('[redacted]', $activity->changes['attributes']['api_token']);
    }

    public function test_a_morph_alias_resolves_to_its_model(): void
    {
        Relation::morphMap(['account' => ArrAccount::class]);
        $this->legacyRow('account');

        $response = $this->actingAs(ArrUser::create([]))->getJson('/_kinetix/activity')->assertOk();

        $this->assertSame('[redacted]', $response->json('data.0.changes.attributes.national_id'));
    }

    public function test_an_unknown_subject_type_falls_back_to_secret_looking_keys(): void
    {
        $this->legacyRow('App\\Gone\\Model', ['name' => 'Acme', 'api_token' => 'tok_live', 'national_id' => 'NID-1']);

        $response = $this->actingAs(ArrUser::create([]))->getJson('/_kinetix/activity')->assertOk();

        $this->assertSame([
            'name'        => 'Acme',
            'api_token'   => '[redacted]',
            'national_id' => 'NID-1',
        ], $response->json('data.0.changes.attributes'));
    }

    public function test_reading_adds_no_query(): void
    {
        $this->legacyRow(ArrAccount::class);
        $this->legacyRow(ArrAccount::class);
        $user = ArrUser::create([]);

        DB::enableQueryLog();
        $this->actingAs($user)->getJson('/_kinetix/activity')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The page and its count (the native driver eager-loads no causer here).
        $this->assertLessThanOrEqual(3, $queries);
    }

    public function test_the_command_rewrites_history_once(): void
    {
        $this->legacyRow(ArrAccount::class);
        $this->legacyRow(ArrAccount::class);
        $this->legacyRow(ArrAccount::class, ['name' => 'Clean']);

        $this->artisan('kinetix:activity:redact', ['--dry-run' => true])
            ->expectsOutputToContain('2 activity row(s) would be redacted.')
            ->assertSuccessful();
        $this->assertStringContainsString('tok_live', (string) DB::table('kinetix_activity')->value('properties'));

        $this->artisan('kinetix:activity:redact')
            ->expectsOutputToContain('Redacted 2 activity row(s).')
            ->assertSuccessful();

        foreach (DB::table('kinetix_activity')->pluck('properties') as $properties) {
            $this->assertStringNotContainsString('tok_live', (string) $properties);
            $this->assertStringNotContainsString('NID-1', (string) $properties);
        }

        $this->artisan('kinetix:activity:redact')
            ->expectsOutputToContain('Redacted 0 activity row(s).')
            ->assertSuccessful();
    }

    /**
     * A row as written before 0.275: the raw values in the diff.
     *
     * @param array<string, mixed>|null $attributes
     */
    private function legacyRow(string $subjectType, ?array $attributes = null): void
    {
        Activity::query()->insert([
            'event'        => 'updated',
            'subject_type' => $subjectType,
            'subject_id'   => 1,
            'properties'   => json_encode([
                'old'        => $attributes === null ? ['name' => 'Old', 'secret' => 'old-secret'] : [],
                'attributes' => $attributes ?? [
                    'name'        => 'Acme',
                    'api_token'   => 'tok_live',
                    'secret'      => 'top-secret',
                    'national_id' => 'NID-1',
                    'password'    => '$2y$hash',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
