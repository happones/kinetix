<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;
use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialBinding;
use Happones\Kinetix\Confidential\ConfidentialCipher;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BoundPatient extends Model
{
    use HasConfidentialAttributes;

    protected $table = 'bound_patients';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ssn' => ConfidentialCast::class, 'tax_id' => ConfidentialCast::class];
    }
}

class ObservedPatient extends BoundPatient
{
    public static ?string $seen = null;

    protected static function booted(): void
    {
        static::created(static function (ObservedPatient $patient): void {
            static::$seen = Confidential::revealed(fn () => $patient->ssn);
        });
    }
}

/**
 * A confidential value is sealed to its table, column and row: someone with
 * write access to the database can't move a ciphertext to another row or
 * column (or edit its envelope) and have the app reveal it.
 */
class ConfidentialEnvelopeBindingTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.confidential.enabled', true);
        $app['config']->set('kinetix.confidential.key_manager', CountingKeyManager::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        ConfidentialBinding::flush();

        Schema::create('kinetix_confidential_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id')->unique();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->boolean('is_current')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bound_patients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('ssn')->nullable();
            $table->text('tax_id')->nullable();
        });

        ConfidentialKey::create([
            'key_id'      => 'key-1',
            'driver'      => 'local',
            'wrapped_key' => (new LocalKeyManager)->generateDataKey()['wrapped'],
            'is_current'  => true,
        ]);

        CountingKeyManager::$unwraps = 0;
    }

    public function test_a_created_row_is_bound_to_its_key_with_one_update(): void
    {
        DB::enableQueryLog();
        $patient = BoundPatient::create(['ssn' => '111-11-1111']);
        $writes  = array_values(array_filter(DB::getQueryLog(), static fn (array $q): bool => ! str_starts_with(strtolower($q['query']), 'select')));
        DB::disableQueryLog();

        $this->assertCount(2, $writes, 'one insert and one binding update');
        $this->assertSame(['v' => 2, 'r' => $patient->getKey()], $this->envelope($patient->getKey(), 'ssn'));
        $this->assertSame('111-11-1111', $this->reveal($patient->getKey(), 'ssn'));
        $this->assertFalse($patient->isDirty('ssn'));
    }

    public function test_a_ciphertext_copied_to_another_row_does_not_reveal(): void
    {
        $alice = BoundPatient::create(['ssn' => 'ALICE-SSN']);
        $bob   = BoundPatient::create(['ssn' => 'BOB-SSN']);

        DB::table('bound_patients')->where('id', $alice->getKey())->update([
            'ssn' => DB::table('bound_patients')->where('id', $bob->getKey())->value('ssn'),
        ]);

        $this->assertRevealFails($alice->getKey(), 'ssn', 'BOB-SSN');
    }

    public function test_a_ciphertext_copied_to_another_column_does_not_reveal(): void
    {
        $patient = BoundPatient::create(['ssn' => 'THE-SSN', 'tax_id' => 'THE-TAX']);

        DB::table('bound_patients')->where('id', $patient->getKey())->update([
            'tax_id' => DB::table('bound_patients')->where('id', $patient->getKey())->value('ssn'),
        ]);

        $this->assertRevealFails($patient->getKey(), 'tax_id', 'THE-SSN');
    }

    public function test_unbinding_the_envelope_does_not_reveal(): void
    {
        $patient       = BoundPatient::create(['ssn' => 'THE-SSN']);
        $envelope      = json_decode((string) DB::table('bound_patients')->value('ssn'), true);
        $envelope['r'] = null;
        DB::table('bound_patients')->update(['ssn' => json_encode($envelope)]);

        $this->assertRevealFails($patient->getKey(), 'ssn', 'THE-SSN');
    }

    public function test_a_shortened_tag_is_refused(): void
    {
        $cipher = app(ConfidentialCipher::class);

        foreach ([$cipher->encrypt('x'), BoundPatient::create(['ssn' => 'x'])->getRawOriginal('ssn')] as $stored) {
            $envelope        = json_decode((string) $stored, true);
            $envelope['tag'] = base64_encode(substr((string) base64_decode($envelope['tag']), 0, 8));

            try {
                $cipher->decrypt((string) json_encode($envelope));
                $this->fail('An 8-byte tag was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('tag length', $exception->getMessage());
            }
        }
    }

    public function test_a_replica_reads_its_value_and_binds_to_its_own_key(): void
    {
        $source  = BoundPatient::create(['ssn' => 'COPY-ME']);
        $replica = $source->replicate();
        $replica->save();

        $this->assertNotSame($source->getKey(), $replica->getKey());
        $this->assertSame('COPY-ME', $this->reveal($replica->getKey(), 'ssn'));
        $this->assertSame(['v' => 2, 'r' => $replica->getKey()], $this->envelope($replica->getKey(), 'ssn'));
    }

    public function test_a_v1_envelope_reads_only_while_legacy_envelopes_are_allowed(): void
    {
        $patient = BoundPatient::create(['name' => 'Old']);
        DB::table('bound_patients')->update(['ssn' => app(ConfidentialCipher::class)->encrypt('LEGACY')]);

        $this->assertSame('LEGACY', $this->reveal($patient->getKey(), 'ssn'));

        config()->set('kinetix.confidential.legacy_envelopes', false);

        $this->expectException(RuntimeException::class);
        $this->reveal($patient->getKey(), 'ssn');
    }

    public function test_writing_back_the_mask_keeps_the_ciphertext(): void
    {
        $patient = BoundPatient::create(['ssn' => '123-45-6789']);
        $stored  = DB::table('bound_patients')->value('ssn');

        $fresh      = BoundPatient::query()->findOrFail($patient->getKey());
        $fresh->ssn = $fresh->ssn;
        $fresh->save();

        $this->assertSame($stored, DB::table('bound_patients')->value('ssn'));
    }

    public function test_encrypt_existing_rewraps_v1_as_row_bound_v2(): void
    {
        $patient = BoundPatient::create(['name' => 'Old']);
        DB::table('bound_patients')->update(['ssn' => app(ConfidentialCipher::class)->encrypt('LEGACY')]);

        $this->artisan('kinetix:confidential:encrypt-existing', ['model' => BoundPatient::class, '--column' => ['ssn']])
            ->expectsOutputToContain('Encrypted or re-wrapped 1 row(s)')
            ->assertSuccessful();

        $this->assertSame(['v' => 2, 'r' => $patient->getKey()], $this->envelope($patient->getKey(), 'ssn'));
        $this->assertSame('LEGACY', $this->reveal($patient->getKey(), 'ssn'));
    }

    public function test_the_cipher_without_a_context_still_writes_v1(): void
    {
        $this->assertSame(1, json_decode(app(ConfidentialCipher::class)->encrypt('x'), true)['v']);
    }

    public function test_reads_reuse_the_memoized_data_key(): void
    {
        $patient = BoundPatient::create(['ssn' => 'MEMO']);

        for ($read = 0; $read < 10; $read++) {
            $this->reveal($patient->getKey(), 'ssn');
        }

        $this->assertSame(1, CountingKeyManager::$unwraps);
    }

    public function test_a_keyless_read_is_checked_against_the_column(): void
    {
        BoundPatient::create(['ssn' => 'PLUCKED']);

        $this->assertSame(['PLUCKED'], Confidential::revealed(fn () => BoundPatient::query()->pluck('ssn')->all()));
    }

    /**
     * Documented limit: a read that doesn't know its row checks the table
     * and column only, so a copied envelope reads there.
     */
    public function test_a_keyless_read_of_a_copied_envelope_is_not_row_checked(): void
    {
        $victim = BoundPatient::create(['ssn' => 'VICTIM']);
        $mine   = BoundPatient::create(['ssn' => 'MINE']);
        $this->copyRaw($victim->getKey(), $mine->getKey());

        $this->assertSame('VICTIM', Confidential::revealed(fn () => BoundPatient::query()->whereKey($mine->getKey())->value('ssn')));
        $this->assertRevealFails($mine->getKey(), 'ssn', 'VICTIM');
    }

    /**
     * Documented limit: a row written without model events stays bound to
     * its column; strict mode refuses it on a keyed read, encrypt-existing
     * binds it, and the doctor counts it.
     */
    public function test_a_quietly_created_value_is_column_bound_until_rewrapped(): void
    {
        $victim = BoundPatient::createQuietly(['ssn' => 'QUIET']);
        $mine   = BoundPatient::create(['ssn' => 'MINE']);

        $this->assertSame(['v' => 2, 'r' => null], $this->envelope($victim->getKey(), 'ssn'));

        Artisan::call('kinetix:doctor', ['--json' => true]);
        $this->assertStringContainsString('0 v1, 1 column-bound', Artisan::output());

        $this->copyRaw($victim->getKey(), $mine->getKey());
        $this->assertSame('QUIET', $this->reveal($mine->getKey(), 'ssn'), 'lenient mode: column-bound reads on any row');

        config()->set('kinetix.confidential.legacy_envelopes', false);
        $this->assertRevealFails($mine->getKey(), 'ssn', 'QUIET');

        $this->artisan('kinetix:confidential:encrypt-existing', ['model' => BoundPatient::class, '--column' => ['ssn']])->assertSuccessful();

        $this->assertSame(['v' => 2, 'r' => $victim->getKey()], $this->envelope($victim->getKey(), 'ssn'));
        $this->assertSame('QUIET', $this->reveal($victim->getKey(), 'ssn'));
        $this->assertFalse(config('kinetix.confidential.legacy_envelopes'));
    }

    public function test_strict_mode_lets_a_created_listener_read_the_value_it_just_sealed(): void
    {
        config()->set('kinetix.confidential.legacy_envelopes', false);

        $patient = ObservedPatient::create(['ssn' => 'OBSERVED']);

        $this->assertSame('OBSERVED', ObservedPatient::$seen);
        $this->assertSame(['v' => 2, 'r' => $patient->getKey()], $this->envelope($patient->getKey(), 'ssn'));
        $this->assertSame('OBSERVED', $this->reveal($patient->getKey(), 'ssn'));
    }

    public function test_strict_mode_refuses_a_copied_column_bound_value_after_refresh(): void
    {
        config()->set('kinetix.confidential.legacy_envelopes', false);
        $victim = BoundPatient::createQuietly(['ssn' => 'VICTIM']);

        // Never bound (no model events): the instance itself still reads
        // the envelope it sealed...
        $patient = BoundPatient::createQuietly(['ssn' => 'MINE']);
        $this->assertSame('MINE', Confidential::revealed(fn () => $patient->ssn));

        // ...but a value copied in at the database level arrives through a
        // fresh read and is refused.
        $this->copyRaw($victim->getKey(), $patient->getKey());
        $patient->refresh();

        $this->expectException(RuntimeException::class);
        Confidential::revealed(fn () => $patient->ssn);
    }

    public function test_strict_mode_still_replicates(): void
    {
        config()->set('kinetix.confidential.legacy_envelopes', false);

        $source  = BoundPatient::create(['ssn' => 'COPY-ME']);
        $replica = $source->replicate();
        $replica->save();

        $this->assertSame('COPY-ME', Confidential::revealed(fn () => $replica->ssn));
        $this->assertSame('COPY-ME', $this->reveal($replica->getKey(), 'ssn'));
        $this->assertSame(['v' => 2, 'r' => $replica->getKey()], $this->envelope($replica->getKey(), 'ssn'));
    }

    private function copyRaw(int|string $from, int|string $to): void
    {
        DB::table('bound_patients')->where('id', $to)->update([
            'ssn' => DB::table('bound_patients')->where('id', $from)->value('ssn'),
        ]);
    }

    /** @return array{v: int, r: int|string|null}|null */
    private function envelope(int|string $id, string $column): ?array
    {
        return app(ConfidentialCipher::class)->describe((string) DB::table('bound_patients')->where('id', $id)->value($column));
    }

    private function reveal(int|string $id, string $column): ?string
    {
        return Confidential::revealed(fn () => BoundPatient::query()->findOrFail($id)->getAttribute($column));
    }

    private function assertRevealFails(int|string $id, string $column, string $secret): void
    {
        try {
            $value = $this->reveal($id, $column);
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertNotSame($secret, $value);
        $this->fail('A moved ciphertext was revealed.');
    }
}
