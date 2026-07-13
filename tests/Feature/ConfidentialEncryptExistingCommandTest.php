<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;
use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EncryptExistingTestPatient extends Model
{
    use HasConfidentialAttributes;

    protected $table = 'encrypt_existing_test_patients';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ssn' => ConfidentialCast::class];
    }
}

class ConfidentialEncryptExistingCommandTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('encrypt_existing_test_patients', function (Blueprint $table) {
            $table->id();
            $table->text('ssn')->nullable();
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

        $generated = (new LocalKeyManager)->generateDataKey();

        ConfidentialKey::create([
            'key_id'      => 'test-key',
            'driver'      => 'local',
            'wrapped_key' => $generated['wrapped'],
            'is_current'  => true,
        ]);

        // Raw insert bypasses Eloquent entirely, so no cast runs — this is
        // real, already-existing plaintext, exactly like a column that had
        // ConfidentialCast added to it after it already held live data.
        DB::table('encrypt_existing_test_patients')->insert([
            ['id' => 1, 'ssn' => '111-11-1111'],
            ['id' => 2, 'ssn' => '222-22-2222'],
        ]);
    }

    public function test_migrates_existing_plaintext_into_valid_encrypted_envelopes(): void
    {
        $this->artisan(
            'kinetix:confidential:encrypt-existing',
            ['model' => EncryptExistingTestPatient::class],
        )->assertSuccessful();

        $raw = DB::table('encrypt_existing_test_patients')->where('id', 1)->value('ssn');
        $this->assertNotSame('111-11-1111', $raw);
        $this->assertNotNull(json_decode((string) $raw, true));

        $patient = EncryptExistingTestPatient::find(1);
        $this->assertNotNull($patient);

        Confidential::revealed(function () use ($patient): void {
            $this->assertSame('111-11-1111', $patient->ssn);
        });
    }

    public function test_column_option_overrides_the_auto_detected_confidential_columns(): void
    {
        $this->artisan('kinetix:confidential:encrypt-existing', [
            'model'    => EncryptExistingTestPatient::class,
            '--column' => ['ssn'],
        ])->assertSuccessful();

        $raw = DB::table('encrypt_existing_test_patients')->where('id', 2)->value('ssn');
        $this->assertNotSame('222-22-2222', $raw);
    }
}
