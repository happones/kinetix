<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\ConfidentialCipher;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ConfidentialRotateKeyCommandTest extends TestCase
{
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
    }

    public function test_first_run_creates_the_initial_current_key(): void
    {
        $this->assertSame(0, ConfidentialKey::query()->count());

        $this->artisan('kinetix:confidential:rotate-key')->assertSuccessful();

        $this->assertSame(1, ConfidentialKey::query()->count());
        $this->assertSame(1, ConfidentialKey::query()->where('is_current', true)->count());
    }

    public function test_second_run_retires_the_old_key_and_both_stay_decryptable(): void
    {
        $this->artisan('kinetix:confidential:rotate-key')->assertSuccessful();

        $envelope = app(ConfidentialCipher::class)->encrypt('encrypted-under-key-one');

        $this->artisan('kinetix:confidential:rotate-key')->assertSuccessful();

        $this->assertSame(2, ConfidentialKey::query()->count());
        $this->assertSame(1, ConfidentialKey::query()->where('is_current', true)->count());
        $this->assertSame(1, ConfidentialKey::query()->whereNotNull('retired_at')->count());

        // The historical envelope (encrypted under the now-retired key) still
        // decrypts correctly after rotation.
        $this->assertSame('encrypted-under-key-one', app(ConfidentialCipher::class)->decrypt($envelope));

        // New writes are now encrypted under the new current key.
        $newEnvelope  = app(ConfidentialCipher::class)->encrypt('encrypted-under-key-two');
        $currentKeyId = ConfidentialKey::query()->where('is_current', true)->value('key_id');

        $this->assertSame($currentKeyId, json_decode($newEnvelope, true)['k']);
    }
}
