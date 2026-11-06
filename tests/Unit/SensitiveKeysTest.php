<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\SensitiveKeys;
use PHPUnit\Framework\TestCase;

/** The secret-looking key rule, pinned key by key. */
class SensitiveKeysTest extends TestCase
{
    public function test_secret_keys_match(): void
    {
        foreach (['password', 'client_secret', 'access_token', 'Authorization', 'x-api-key', 'apiKey', 'idempotency_key', 'cookie', 'privateKey', 'otp', 'card_cvv', 'Set-Cookie', 'pwd', 'user_pwd', 'bearer', 'jwt', 'refreshJwt', 'x-auth', 'auth', 'passphrase'] as $key) {
            $this->assertTrue(SensitiveKeys::matches($key), "{$key} should match");
        }
    }

    public function test_ordinary_keys_do_not_match(): void
    {
        foreach (['keyword', 'monkey', 'turkey', 'description', 'name', 'sku', 'pinned', 'author', 'author_id', 'authored_at', 'passport', 'compass', 'passenger', 0, 1] as $key) {
            $this->assertFalse(SensitiveKeys::matches($key), "{$key} should not match");
        }
    }

    public function test_the_rule_errs_on_the_side_of_hiding(): void
    {
        // Contains `token`; a `key` segment.
        $this->assertTrue(SensitiveKeys::matches('token_count'));
        $this->assertTrue(SensitiveKeys::matches('key_results'));
    }

    public function test_extra_substrings_are_added(): void
    {
        $this->assertFalse(SensitiveKeys::matches('patient_mrn_number'));
        $this->assertTrue(SensitiveKeys::matches('patient_mrn_number', ['mrn_number']));
    }

    public function test_redaction_reaches_every_depth_and_list_item(): void
    {
        $redacted = SensitiveKeys::redact([
            'user'  => ['profile' => ['api_token' => 'T', 'name' => 'Ada']],
            'items' => [['client_secret' => 'S', 'sku' => 'A-1']],
        ]);

        $this->assertSame([
            'user'  => ['profile' => ['api_token' => '[redacted]', 'name' => 'Ada']],
            'items' => [['client_secret' => '[redacted]', 'sku' => 'A-1']],
        ], $redacted);
    }

    public function test_arrays_deeper_than_the_cap_are_replaced(): void
    {
        $deep = ['leaf' => 'x'];

        for ($level = 0; $level < SensitiveKeys::MAX_DEPTH + 2; $level++) {
            $deep = ['n' => $deep];
        }

        $this->assertStringContainsString('[redacted]', (string) json_encode(SensitiveKeys::redact($deep)));
        $this->assertStringNotContainsString('leaf', (string) json_encode(SensitiveKeys::redact($deep)));
    }
}
