<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;

class LocalKeyManagerTest extends TestCase
{
    public function test_generate_data_key_returns_a_32_byte_key_and_a_different_wrapped_form(): void
    {
        $generated = (new LocalKeyManager)->generateDataKey();

        $this->assertSame(32, strlen($generated['plaintext']));
        $this->assertNotSame($generated['plaintext'], $generated['wrapped']);
    }

    public function test_unwrap_round_trips_back_to_the_original_raw_key(): void
    {
        $manager   = new LocalKeyManager;
        $generated = $manager->generateDataKey();

        $this->assertSame($generated['plaintext'], $manager->unwrap($generated['wrapped']));
    }
}
