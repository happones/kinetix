<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Happones\Kinetix\Support\KinetixStateReset;
use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Webhooks\DispatchWebhookJob;
use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Webhook signing secrets are encrypted at rest, without breaking hosts
 * that still hold plaintext ones or haven't widened the column yet.
 */
class WebhookSecretEncryptionTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.webhooks.enabled', true);
        $app['config']->set('kinetix.webhooks.driver', 'native');
    }

    protected function setUp(): void
    {
        parent::setUp();

        EncryptedWithPlaintextFallback::flush();

        foreach (['000004_create_kinetix_webhook_endpoints_table', '000005_create_kinetix_webhook_logs_table'] as $file) {
            (require __DIR__."/../../database/migrations/2026_01_01_{$file}.php")->up();
        }
    }

    protected function tearDown(): void
    {
        EncryptedWithPlaintextFallback::flush();

        parent::tearDown();
    }

    public function test_a_new_secret_is_stored_encrypted_and_signs_like_the_plaintext(): void
    {
        $endpoint = $this->endpoint('whsec_live');

        $raw = (string) DB::table('kinetix_webhook_endpoints')->value('secret');

        $this->assertNotSame('whsec_live', $raw);
        $this->assertTrue(EncryptedWithPlaintextFallback::isEncryptedPayload($raw));
        $this->assertSame('whsec_live', $endpoint->fresh()?->secret);
        $this->assertSignedWith('whsec_live', $endpoint);
    }

    public function test_a_legacy_plaintext_secret_still_signs(): void
    {
        $endpoint = $this->legacyEndpoint('whsec_legacy');

        $this->assertSame('whsec_legacy', $endpoint->secret);
        $this->assertSignedWith('whsec_legacy', $endpoint);
    }

    public function test_the_command_encrypts_exactly_the_plaintext_rows_once(): void
    {
        $legacy  = $this->legacyEndpoint('whsec_legacy');
        $current = $this->endpoint('whsec_current');
        $before  = (string) DB::table('kinetix_webhook_endpoints')->where('id', $current->id)->value('secret');

        $this->artisan('kinetix:webhooks:encrypt-secrets')
            ->expectsOutputToContain('Encrypted 1 webhook secret(s).')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('kinetix_webhook_endpoints')->where('id', $current->id)->value('secret'));
        $this->assertTrue(EncryptedWithPlaintextFallback::isEncryptedPayload((string) DB::table('kinetix_webhook_endpoints')->where('id', $legacy->id)->value('secret')));
        $this->assertSignedWith('whsec_legacy', $legacy->fresh());

        $this->artisan('kinetix:webhooks:encrypt-secrets')
            ->expectsOutputToContain('Encrypted 0 webhook secret(s).')
            ->assertSuccessful();
    }

    public function test_a_column_too_small_keeps_plaintext_and_the_command_refuses(): void
    {
        $this->pretendNarrowColumn();

        $endpoint = $this->endpoint('whsec_narrow');

        $this->assertSame('whsec_narrow', DB::table('kinetix_webhook_endpoints')->value('secret'));
        $this->assertSame('whsec_narrow', $endpoint->fresh()?->secret);

        $this->artisan('kinetix:webhooks:encrypt-secrets')->assertExitCode(1);
    }

    public function test_the_capacity_memo_is_dropped_between_jobs(): void
    {
        $this->pretendNarrowColumn();

        KinetixStateReset::run(false);

        $this->assertTrue(EncryptedWithPlaintextFallback::columnHoldsCiphertext(null, 'kinetix_webhook_endpoints', 'secret'));
    }

    public function test_column_capacity_by_type(): void
    {
        $this->assertFalse(EncryptedWithPlaintextFallback::fits('varchar', 'varchar(255)'));
        $this->assertFalse(EncryptedWithPlaintextFallback::fits('varchar', 'character varying(255)'));
        $this->assertTrue(EncryptedWithPlaintextFallback::fits('varchar', 'varchar(512)'));
        $this->assertTrue(EncryptedWithPlaintextFallback::fits('text', 'text'));
        $this->assertTrue(EncryptedWithPlaintextFallback::fits('longtext', 'longtext'));
        $this->assertTrue(EncryptedWithPlaintextFallback::fits('character varying', 'character varying'));

        // SQLite enforces no length.
        $this->assertTrue(EncryptedWithPlaintextFallback::columnHoldsCiphertext(null, 'kinetix_webhook_endpoints', 'secret'));
        $this->assertFalse(EncryptedWithPlaintextFallback::columnHoldsCiphertext(null, 'kinetix_webhook_endpoints', 'missing'));
    }

    public function test_plaintext_is_never_mistaken_for_a_payload(): void
    {
        $this->assertFalse(EncryptedWithPlaintextFallback::isEncryptedPayload('whsec_'.str_repeat('a', 40)));
        $this->assertFalse(EncryptedWithPlaintextFallback::isEncryptedPayload(base64_encode('{"hello":"world"}')));
        $this->assertTrue(EncryptedWithPlaintextFallback::isEncryptedPayload(Crypt::encryptString('x')));
    }

    public function test_the_migration_widens_an_existing_narrow_column_and_is_idempotent(): void
    {
        Schema::drop('kinetix_webhook_logs');
        Schema::drop('kinetix_webhook_endpoints');
        DB::statement('create table "kinetix_webhook_endpoints" ("id" integer primary key autoincrement not null, "secret" varchar(255) not null)');
        DB::table('kinetix_webhook_endpoints')->insert(['secret' => 'whsec_kept']);

        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000051_widen_secret_on_kinetix_webhook_endpoints_table.php';
        $migration->up();
        $migration->up();
        $migration->down();

        $column = collect(Schema::getColumns('kinetix_webhook_endpoints'))->firstWhere('name', 'secret');

        $this->assertSame('text', strtolower((string) $column['type_name']));
        $this->assertSame('whsec_kept', DB::table('kinetix_webhook_endpoints')->value('secret'));
    }

    public function test_the_migration_skips_a_missing_table(): void
    {
        Schema::drop('kinetix_webhook_logs');
        Schema::drop('kinetix_webhook_endpoints');

        (require __DIR__.'/../../database/migrations/2026_01_01_000051_widen_secret_on_kinetix_webhook_endpoints_table.php')->up();

        $this->assertFalse(Schema::hasTable('kinetix_webhook_endpoints'));
    }

    public function test_the_webhooks_tag_publishes_the_migration(): void
    {
        $paths = array_keys(ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-webhooks-migrations'));

        $this->assertNotEmpty(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '000051_widen_secret_on_kinetix_webhook_endpoints_table.php')));
    }

    public function test_doctor_reports_plaintext_narrow_and_undecryptable_secrets(): void
    {
        $this->legacyEndpoint('whsec_legacy');
        DB::table('kinetix_webhook_endpoints')->insert([
            'name'   => 'Foreign',
            'url'    => 'https://8.8.8.8/hook',
            'secret' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('x'),
            'events' => '[]',
            'active' => true,
        ]);
        $this->pretendNarrowColumn();

        Artisan::call('kinetix:doctor', ['--json' => true]);
        $messages = array_column((array) json_decode(Artisan::output(), true)['findings'], 'message', 'message');

        $this->assertArrayHasKey('1 endpoint secret(s) stored in plaintext', $messages);
        $this->assertArrayHasKey('the secret column is too small for encrypted secrets, so they are stored in plaintext', $messages);
        $this->assertArrayHasKey("1 secret(s) can't be decrypted with the current APP_KEY", $messages);
    }

    public function test_the_secret_never_serializes(): void
    {
        $this->assertArrayNotHasKey('secret', $this->endpoint('whsec_live')->toArray());
    }

    private function endpoint(string $secret): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'name'   => 'Endpoint',
            'url'    => 'https://8.8.8.8/hook',
            'secret' => $secret,
            'events' => ['order.created'],
            'active' => true,
        ]);
    }

    private function legacyEndpoint(string $secret): WebhookEndpoint
    {
        $id = DB::table('kinetix_webhook_endpoints')->insertGetId([
            'name'   => 'Legacy',
            'url'    => 'https://8.8.8.8/hook',
            'secret' => $secret,
            'events' => '["order.created"]',
            'active' => true,
        ]);

        return WebhookEndpoint::query()->findOrFail($id);
    }

    private function assertSignedWith(string $secret, ?WebhookEndpoint $endpoint): void
    {
        $this->assertNotNull($endpoint);
        Http::fake(['*' => Http::response('ok', 200)]);

        (new DispatchWebhookJob($endpoint->id, 'order.created', ['id' => 7]))->handle();

        Http::assertSent(static fn ($request): bool => $request->hasHeader('X-Kinetix-Signature', hash_hmac('sha256', $request->body(), $secret)));
    }

    /** A MySQL `varchar(255)` column, as the memo would have found it. */
    private function pretendNarrowColumn(): void
    {
        (new \ReflectionProperty(EncryptedWithPlaintextFallback::class, 'capacity'))
            ->setValue(null, ['|kinetix_webhook_endpoints|secret' => false]);
    }
}
