<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Api\ApiLog;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

/**
 * API logs never keep a secret: request bodies and JSON response bodies are
 * redacted at any depth, before they are cut to size.
 */
class ApiLogRedactionTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.api_logs.enabled', true);
        $app['config']->set('kinetix.api_logs.log_request_body', true);
        $app['config']->set('kinetix.api_logs.log_response_body', true);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->post('/api/echo', fn () => response()->json([
            'data' => ['access_token' => 'Z', 'nested' => [['password' => 'P', 'id' => 7]]],
        ]))->middleware(['kinetix.api-log']);

        $router->post('/api/text', fn () => response('plain text with token=abc', 200, ['Content-Type' => 'text/plain']))
            ->middleware(['kinetix.api-log']);

        $router->post('/api/huge', fn () => response()->json(['rows' => str_repeat('a', 1_100_000), 'api_key' => 'K']))
            ->middleware(['kinetix.api-log']);

        $router->post('/api/tail', fn () => response()->json(['filler' => str_repeat('a', 200), 'secret' => 'TAIL-SECRET']))
            ->middleware(['kinetix.api-log']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });

        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000018_create_kinetix_api_logs_table.php';
        $migration->up();
    }

    public function test_nested_request_secrets_are_not_stored(): void
    {
        $this->postJson('/api/echo', [
            'user'  => ['profile' => ['api_token' => 'T-SECRET']],
            'items' => [['client_secret' => 'S-SECRET', 'sku' => 'A-1']],
        ])->assertOk();

        $stored = (string) json_encode(ApiLog::query()->firstOrFail()->request_body);

        $this->assertStringNotContainsString('T-SECRET', $stored);
        $this->assertStringNotContainsString('S-SECRET', $stored);
        $this->assertStringContainsString('A-1', $stored);
    }

    public function test_json_response_secrets_are_not_stored_and_it_stays_valid_json(): void
    {
        $this->postJson('/api/echo')->assertOk();

        $body = (string) ApiLog::query()->firstOrFail()->response_body;

        $this->assertStringNotContainsString('"Z"', $body);
        $this->assertStringNotContainsString('"P"', $body);
        $this->assertSame(7, json_decode($body, true)['data']['nested'][0]['id']);
    }

    public function test_a_response_too_large_to_redact_is_stored_as_a_marker(): void
    {
        $this->postJson('/api/huge')->assertOk();

        $this->assertSame('[omitted: too large to redact]', ApiLog::query()->firstOrFail()->response_body);
    }

    public function test_a_non_json_response_is_stored_as_before(): void
    {
        $this->postJson('/api/text')->assertOk();

        $this->assertSame('plain text with token=abc', ApiLog::query()->firstOrFail()->response_body);
    }

    public function test_config_additions_match_as_substrings(): void
    {
        config()->set('kinetix.api_logs.redact', ['mrn_number']);

        $this->postJson('/api/echo', ['patient_mrn_number' => '123-45-6789'])->assertOk();

        $this->assertSame('[redacted]', ApiLog::query()->firstOrFail()->request_body['patient_mrn_number']);
    }

    public function test_redaction_happens_before_truncation(): void
    {
        config()->set('kinetix.api_logs.body_limit', 240);

        $this->postJson('/api/tail')->assertOk();

        $this->assertStringNotContainsString('TAIL', (string) ApiLog::query()->firstOrFail()->response_body);
    }
}
