<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Activity\Activity;
use Happones\Kinetix\Activity\Concerns\LogsKinetixActivity;
use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

class RedactedAccount extends Model
{
    use LogsKinetixActivity;

    protected $table = 'redacted_accounts';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'secret'      => 'encrypted',
            'settings'    => AsEncryptedArrayObject::class,
            'national_id' => ConfidentialCast::class,
        ];
    }
}

/**
 * The activity log keeps who changed what, never the value of a hidden or
 * encrypted attribute: those are recorded as `[redacted]` on both sides of
 * the diff, and old values are the stored (raw) ones.
 */
class ActivityRedactionTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.activity.enabled', true);
        $app['config']->set('kinetix.activity.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('redacted_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('api_token')->nullable();
            $table->text('secret')->nullable();
            $table->text('settings')->nullable();
            $table->text('national_id')->nullable();
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

        Schema::create('kinetix_confidential_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id')->unique();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->boolean('is_current')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        ConfidentialKey::create([
            'key_id'      => 'test-key',
            'driver'      => 'local',
            'wrapped_key' => (new LocalKeyManager)->generateDataKey()['wrapped'],
            'is_current'  => true,
        ]);
    }

    private function account(): RedactedAccount
    {
        return RedactedAccount::create([
            'name'        => 'Acme',
            'api_token'   => 'tok-original',
            'secret'      => 'hunter2',
            'settings'    => ['pin' => '0000'],
            'national_id' => '123-45-6789',
        ]);
    }

    public function test_created_redacts_hidden_and_encrypted_attributes(): void
    {
        $this->account();

        $attributes = Activity::query()->where('event', 'created')->firstOrFail()->properties['attributes'];

        $this->assertSame('Acme', $attributes['name']);

        foreach (['api_token', 'secret', 'settings', 'national_id'] as $key) {
            $this->assertSame('[redacted]', $attributes[$key], "{$key} must be redacted");
        }
    }

    public function test_updated_redacts_both_sides_and_never_stores_a_decrypted_old_value(): void
    {
        $account = $this->account();

        Confidential::revealed(static function () use ($account): void {
            $account->update([
                'name'        => 'Acme Inc',
                'api_token'   => 'tok-new',
                'secret'      => 'correct-horse',
                'settings'    => ['pin' => '1234'],
                'national_id' => '987-65-4321',
            ]);
        });

        $row = Activity::query()->where('event', 'updated')->firstOrFail();

        $this->assertSame('Acme', $row->properties['old']['name']);
        $this->assertSame('Acme Inc', $row->properties['attributes']['name']);

        foreach (['api_token', 'secret', 'settings', 'national_id'] as $key) {
            $this->assertSame('[redacted]', $row->properties['old'][$key], "old {$key} must be redacted");
            $this->assertSame('[redacted]', $row->properties['attributes'][$key], "new {$key} must be redacted");
        }

        $stored = (string) json_encode($row->properties);

        foreach (['tok-original', 'hunter2', '0000', '123-45-6789', '6789'] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $stored);
        }
    }

    public function test_a_plain_old_value_is_the_stored_one(): void
    {
        $account = $this->account();
        $account->update(['name' => 'Renamed']);

        $this->assertSame('Acme', Activity::query()->where('event', 'updated')->firstOrFail()->properties['old']['name']);
    }
}
