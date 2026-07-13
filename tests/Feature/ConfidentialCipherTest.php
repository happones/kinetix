<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\ConfidentialCipher;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ConfidentialCipherTest extends TestCase
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
    }

    private function cipher(): ConfidentialCipher
    {
        return app(ConfidentialCipher::class);
    }

    public function test_encrypt_then_decrypt_round_trips_to_the_original_plaintext(): void
    {
        $envelope = $this->cipher()->encrypt('123-45-6789');

        $this->assertNotSame('123-45-6789', $envelope);
        $this->assertSame('123-45-6789', $this->cipher()->decrypt($envelope));
    }

    public function test_tampered_ciphertext_fails_to_decrypt(): void
    {
        $envelope      = json_decode($this->cipher()->encrypt('super-secret'), true);
        $envelope['c'] = base64_encode('not-the-real-ciphertext-bytes!!');

        $this->expectException(RuntimeException::class);

        $this->cipher()->decrypt((string) json_encode($envelope));
    }

    public function test_non_envelope_value_is_treated_as_legacy_plaintext(): void
    {
        $this->assertSame('already-plain-value', $this->cipher()->decrypt('already-plain-value'));
    }
}
