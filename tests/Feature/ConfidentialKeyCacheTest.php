<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;
use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialCipher;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\ConfidentialManager;
use Happones\Kinetix\Confidential\KeyManagers\KeyManager;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CountingKeyManager implements KeyManager
{
    public static int $unwraps = 0;

    public function generateDataKey(): array
    {
        return (new LocalKeyManager)->generateDataKey();
    }

    public function unwrap(string $wrapped): string
    {
        static::$unwraps++;

        return (new LocalKeyManager)->unwrap($wrapped);
    }
}

class RekeyCustomer extends Model
{
    use HasConfidentialAttributes;

    protected $table = 'rekey_customers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ssn' => ConfidentialCast::class];
    }
}

/**
 * The unwrapped data key never leaves the process: it is memoized on the
 * manager for `key_cache_ttl_minutes`, and the shared cache only ever holds
 * the key row's id and WRAPPED key.
 */
class ConfidentialKeyCacheTest extends TestCase
{
    private string $wrapped;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.confidential.enabled', true);
        $app['config']->set('kinetix.confidential.key_manager', CountingKeyManager::class);
        $app['config']->set('kinetix.confidential.key_cache_ttl_minutes', 10);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('kinetix_confidential_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id')->unique();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->boolean('is_current')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        $this->wrapped = (new LocalKeyManager)->generateDataKey()['wrapped'];

        ConfidentialKey::create([
            'key_id'      => 'key-1',
            'driver'      => 'local',
            'wrapped_key' => $this->wrapped,
            'is_current'  => true,
        ]);

        CountingKeyManager::$unwraps = 0;
    }

    private function cacheContents(): string
    {
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);

        return serialize((new \ReflectionProperty($store, 'storage'))->getValue($store));
    }

    public function test_no_cache_entry_holds_the_raw_data_key(): void
    {
        $cipher = app(ConfidentialCipher::class);

        $this->assertSame('123-45-6789', $cipher->decrypt($cipher->encrypt('123-45-6789')));

        $raw = (new LocalKeyManager)->unwrap($this->wrapped);

        $this->assertStringNotContainsString($raw, $this->cacheContents());
        $this->assertStringNotContainsString(base64_encode($raw), $this->cacheContents());
        $this->assertNull(Cache::get('kinetix-confidential-dek:key-1'));
        $this->assertSame(['key_id' => 'key-1', 'wrapped_key' => $this->wrapped], Cache::get('kinetix-confidential:v2:current'));
        $this->assertSame(['key_id' => 'key-1', 'wrapped_key' => $this->wrapped], Cache::get('kinetix-confidential:v2:key:key-1'));
    }

    public function test_the_key_is_unwrapped_once_per_ttl_window(): void
    {
        $cipher = app(ConfidentialCipher::class);
        $stored = $cipher->encrypt('secret');

        for ($i = 0; $i < 5; $i++) {
            $cipher->decrypt($stored);
        }

        $this->assertSame(1, CountingKeyManager::$unwraps);

        $this->travel(11)->minutes();

        $cipher->decrypt($stored);
        $cipher->decrypt($stored);

        $this->assertSame(2, CountingKeyManager::$unwraps);

        app(ConfidentialManager::class)->flushKeys();
        $cipher->decrypt($stored);

        $this->assertSame(3, CountingKeyManager::$unwraps);
    }

    public function test_rotate_key_purges_the_legacy_and_current_cache_entries(): void
    {
        $raw = (new LocalKeyManager)->unwrap($this->wrapped);

        Cache::forever('kinetix-confidential-dek:key-1', $raw);
        Cache::forever('kinetix-confidential-key:key-1', ConfidentialKey::query()->first());
        Cache::forever('kinetix-confidential-current-key', ConfidentialKey::query()->first());
        Cache::forever('kinetix-confidential:v2:current', ['key_id' => 'key-1', 'wrapped_key' => $this->wrapped]);
        Cache::forever('kinetix-confidential:v2:key:key-1', ['key_id' => 'key-1', 'wrapped_key' => $this->wrapped]);

        $this->artisan('kinetix:confidential:rotate-key')->assertSuccessful();

        foreach ([
            'kinetix-confidential-dek:key-1',
            'kinetix-confidential-key:key-1',
            'kinetix-confidential-current-key',
            'kinetix-confidential:v2:current',
            'kinetix-confidential:v2:key:key-1',
        ] as $key) {
            $this->assertFalse(Cache::has($key), $key);
        }

        $this->assertStringNotContainsString($raw, $this->cacheContents());
    }

    /**
     * The documented re-encrypt recipe after a rotation: assigning the
     * revealed plaintext back writes a fresh envelope under the new current
     * key (a fresh IV makes it dirty, and plaintext is never a mask).
     */
    public function test_the_documented_recipe_re_encrypts_rows_under_the_new_key(): void
    {
        Schema::create('rekey_customers', function (Blueprint $table) {
            $table->id();
            $table->text('ssn')->nullable();
        });

        RekeyCustomer::create(['ssn' => '123-45-6789']);
        RekeyCustomer::create(['ssn' => '987-65-4321']);
        RekeyCustomer::create(['ssn' => null]);

        $this->artisan('kinetix:confidential:rotate-key')->assertSuccessful();
        $newKey = (string) ConfidentialKey::query()->where('is_current', true)->value('key_id');
        $this->assertNotSame('key-1', $newKey);

        Confidential::revealed(fn () => RekeyCustomer::query()->cursor()->each(function (RekeyCustomer $customer): void {
            foreach (RekeyCustomer::confidentialColumns() as $column) {
                $customer->{$column} = $customer->{$column};
            }

            $customer->save();
        }));

        $envelopes = DB::table('rekey_customers')->whereNotNull('ssn')->pluck('ssn');
        $this->assertCount(2, $envelopes);

        foreach ($envelopes as $envelope) {
            $this->assertSame($newKey, json_decode((string) $envelope, true)['k']);
        }

        $this->assertSame(
            ['123-45-6789', '987-65-4321', null],
            Confidential::revealed(fn () => RekeyCustomer::query()->orderBy('id')->pluck('ssn')->all()),
        );
    }
}
