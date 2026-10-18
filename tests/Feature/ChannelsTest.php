<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelStatus;
use Happones\Kinetix\Channels\Drivers\TelegramDriver;
use Happones\Kinetix\Channels\Drivers\WhatsAppDriver;
use Happones\Kinetix\Channels\Events\ChannelMessageReceived;
use Happones\Kinetix\Channels\Events\ChannelQrUpdated;
use Happones\Kinetix\Channels\Events\ChannelStatusChanged;
use Happones\Kinetix\Channels\Events\ChannelUpdated;
use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Fakes\FakeEvolutionGateway;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class ChannelUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class ChannelsTest extends TestCase
{
    private FakeEvolutionGateway $evolution;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.url', 'https://app.test');
        $app['config']->set('kinetix.channels.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->evolution = new FakeEvolutionGateway;
        $this->app->instance(EvolutionGateway::class, $this->evolution);
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function ($table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        (include __DIR__.'/../../database/migrations/2026_01_01_000041_create_kinetix_channels_table.php')->up();
    }

    private function manager(): ChannelUser
    {
        $user = ChannelUser::create(['name' => 'Manager']);
        $user->givePermissionTo('channels.manage');

        return $user;
    }

    private function line(array $attributes = []): Channel
    {
        return Channel::create(array_merge([
            'provider'    => 'whatsapp',
            'name'        => 'Sales',
            'external_id' => 'kinetix_line',
            'status'      => ChannelStatus::Connecting,
        ], $attributes));
    }

    private function hook(Channel $channel, array $payload, ?string $secret = null): TestResponse
    {
        $header = $channel->provider === 'telegram' ? TelegramDriver::SECRET_HEADER : WhatsAppDriver::SECRET_HEADER;

        return $this->postJson("/_kinetix/channels/webhook/{$channel->public_id}", $payload, [$header => $secret ?? $channel->webhook_secret]);
    }

    public function test_managing_lines_needs_the_ability(): void
    {
        $this->actingAs(ChannelUser::create(['name' => 'Nobody']))
            ->getJson('/_kinetix/channels')
            ->assertForbidden();

        $this->actingAs($this->manager())
            ->getJson('/_kinetix/channels')
            ->assertOk()
            ->assertJsonPath('providers.0.key', 'whatsapp')
            ->assertJsonPath('providers.0.methods', ['qr', 'pairing_code'])
            ->assertJsonPath('providers.1.methods', ['token'])
            ->assertJsonPath('broadcast', null);
    }

    public function test_a_whatsapp_line_is_provisioned_with_its_webhook_and_opens_on_its_first_qr(): void
    {
        $response = $this->actingAs($this->manager())
            ->postJson('/_kinetix/channels', ['provider' => 'whatsapp', 'name' => 'Sales'])
            ->assertCreated()
            ->assertJsonPath('channel.status', 'connecting')
            ->assertJsonPath('link.qr', 'data:image/png;base64,iVBORw0KGgo=')
            ->assertJsonPath('link.expiresIn', 30)
            ->assertJsonMissingPath('channel.credentials');

        $channel                             = Channel::query()->sole();
        [$instance, $url, $events, $headers] = $this->evolution->callsTo('setWebhook')[0];

        $this->assertSame('kinetix_'.strtolower($channel->public_id), $instance);
        $this->assertSame($instance, $channel->external_id);
        $this->assertSame("https://app.test/_kinetix/channels/webhook/{$channel->public_id}", $url);
        $this->assertContains('MESSAGES_UPSERT', $events);
        $this->assertSame([WhatsAppDriver::SECRET_HEADER => $channel->webhook_secret], $headers);

        // The instance token is stored, encrypted.
        $this->assertSame('instance-token', $channel->credential('token'));
        $this->assertStringNotContainsString('instance-token', (string) DB::table('kinetix_channels')->value('credentials'));
        $this->assertStringNotContainsString($channel->webhook_secret, (string) $response->getContent());
    }

    public function test_an_unreachable_server_saves_no_line_and_leaves_no_instance_behind(): void
    {
        $this->evolution->failOn = 'setWebhook';

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/channels', ['provider' => 'whatsapp', 'name' => 'Sales'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);

        $this->assertSame(0, Channel::query()->count());
        $this->assertCount(1, $this->evolution->callsTo('delete'));
    }

    public function test_a_line_links_by_qr_or_by_a_pairing_code(): void
    {
        $channel = $this->line();
        $user    = $this->manager();

        $this->actingAs($user)
            ->getJson("/_kinetix/channels/{$channel->id}/link?phone=%2B52%2055%201234%205678")
            ->assertOk()
            ->assertJsonPath('link.pairingCode', 'ABCD1234')
            ->assertJsonPath('link.qr', null);

        $this->assertSame('+52 55 1234 5678', $this->evolution->callsTo('connect')[0][1]);

        $this->evolution->state = 'open';

        $this->actingAs($user)
            ->getJson("/_kinetix/channels/{$channel->id}/link")
            ->assertOk()
            ->assertJsonPath('link.status', 'connected')
            ->assertJsonPath('channel.status', 'connected');
    }

    public function test_the_status_the_linker_polls_asks_the_provider_at_most_every_few_seconds(): void
    {
        Event::fake([ChannelStatusChanged::class]);

        $channel = $this->line();
        $user    = $this->manager();

        $this->evolution->state = 'open';

        $this->actingAs($user)->getJson("/_kinetix/channels/{$channel->id}/status")->assertJsonPath('channel.status', 'connected');
        $this->actingAs($user)->getJson("/_kinetix/channels/{$channel->id}/status")->assertOk();

        $this->assertCount(1, $this->evolution->callsTo('state'));
        Event::assertDispatched(ChannelStatusChanged::class, fn (ChannelStatusChanged $event): bool => $event->from === ChannelStatus::Connecting && $event->to === ChannelStatus::Connected);
        $this->assertNotNull($channel->fresh()?->connected_at);
        // Linked by polling: the number comes from the server, not a webhook.
        $this->assertSame('+5215512345678', $channel->fresh()?->handle);
    }

    public function test_a_webhook_without_the_lines_secret_is_refused(): void
    {
        $channel = $this->line();

        $this->hook($channel, ['event' => 'connection.update', 'data' => ['state' => 'open']], 'wrong')->assertUnauthorized();
        $this->postJson('/_kinetix/channels/webhook/01JUNKNOWN', ['event' => 'connection.update'])->assertUnauthorized();

        $this->assertSame(ChannelStatus::Connecting, $channel->fresh()?->status);
    }

    public function test_whatsapp_webhooks_update_the_line_and_announce_qrs_and_messages(): void
    {
        Event::fake([ChannelQrUpdated::class, ChannelMessageReceived::class, ChannelStatusChanged::class]);

        $channel = $this->line();

        $this->hook($channel, ['event' => 'QRCODE_UPDATED', 'data' => ['qrcode' => ['base64' => 'data:image/png;base64,AAA']]])->assertOk();
        Event::assertDispatched(ChannelQrUpdated::class, fn (ChannelQrUpdated $event): bool => $event->qr === 'data:image/png;base64,AAA');

        $this->hook($channel, ['event' => 'connection.update', 'data' => ['state' => 'open', 'wuid' => '5215512345678:12@s.whatsapp.net']])->assertOk();
        $this->assertSame('+5215512345678', $channel->fresh()?->handle);
        $this->assertSame(ChannelStatus::Connected, $channel->fresh()?->status);

        $this->hook($channel, ['event' => 'messages.upsert', 'data' => [
            'key'              => ['remoteJid' => '123456@lid', 'senderPn' => '5215599999999@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG1'],
            'pushName'         => 'Ada',
            'message'          => ['extendedTextMessage' => ['text' => 'Hola, ¿tienen envío?']],
            'messageType'      => 'extendedTextMessage',
            'messageTimestamp' => 1760000000,
        ]])->assertOk();

        // The line's own messages are not inbound.
        $this->hook($channel, ['event' => 'messages.upsert', 'data' => [
            'key'     => ['remoteJid' => '5215599999999@s.whatsapp.net', 'fromMe' => true, 'id' => 'MSG2'],
            'message' => ['conversation' => 'Sí'],
        ]])->assertOk();

        Event::assertDispatchedTimes(ChannelMessageReceived::class, 1);
        $message = Event::dispatched(ChannelMessageReceived::class)->first()[0]->message;

        $this->assertSame('5215599999999', $message->from);
        $this->assertSame('Ada', $message->fromName);
        $this->assertSame('Hola, ¿tienen envío?', $message->text);
        $this->assertSame('text', $message->type);
        $this->assertSame('MSG1', $message->externalId);
        $this->assertFalse($message->isGroup);
        $this->assertSame(1760000000, $message->receivedAt->getTimestamp());
    }

    public function test_a_telegram_line_is_a_bot_checked_and_hooked_by_its_token(): void
    {
        Http::fake([
            'api.telegram.org/bot123:ABC/getMe'      => Http::response(['ok' => true, 'result' => ['id' => 99, 'username' => 'acme_bot', 'first_name' => 'Acme']]),
            'api.telegram.org/bot123:ABC/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/channels', ['provider' => 'telegram', 'name' => 'Support', 'token' => '123:ABC'])
            ->assertCreated()
            ->assertJsonPath('channel.status', 'connected')
            ->assertJsonPath('channel.handle', '@acme_bot')
            ->assertJsonPath('link', null);

        $channel = Channel::query()->sole();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url']          === "https://app.test/_kinetix/channels/webhook/{$channel->public_id}"
            && $request['secret_token'] === $channel->webhook_secret);
        $this->assertSame('123:ABC', $channel->credential('token'));
    }

    public function test_a_rejected_bot_token_is_an_error_under_the_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/channels', ['provider' => 'telegram', 'name' => 'Support', 'token' => '123:BAD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/channels', ['provider' => 'telegram', 'name' => 'Support', 'token' => 'not a token'])
            ->assertJsonValidationErrors(['token']);

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_telegram_messages_reach_the_app_and_replies_go_out_through_the_bot(): void
    {
        Event::fake([ChannelMessageReceived::class]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]])]);

        $channel = $this->line(['provider' => 'telegram', 'status' => ChannelStatus::Connected, 'credentials' => ['token' => '123:ABC']]);

        $this->hook($channel, ['update_id' => 1, 'message' => [
            'message_id' => 7,
            'date'       => 1760000000,
            'chat'       => ['id' => 4242, 'type' => 'private'],
            'from'       => ['id' => 4242, 'first_name' => 'Grace', 'last_name' => 'Hopper'],
            'text'       => '/start',
        ]])->assertOk();

        Event::assertDispatched(ChannelMessageReceived::class, fn (ChannelMessageReceived $event): bool => $event->message->from === '4242'
            && $event->message->fromName                                                                                         === 'Grace Hopper' && $event->message->text === '/start');

        $this->actingAs($this->manager())
            ->postJson("/_kinetix/channels/{$channel->id}/test", ['to' => '4242', 'text' => 'Hi'])
            ->assertOk();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '4242' && $request['text'] === 'Hi');
    }

    public function test_lines_stay_in_their_scope(): void
    {
        $other = $this->line(['team_id' => 5]);

        $this->actingAs($this->manager())->getJson("/_kinetix/channels/{$other->id}/status")->assertNotFound();
        $this->actingAs($this->manager())->deleteJson("/_kinetix/channels/{$other->id}")->assertNotFound();
        $this->actingAs($this->manager())->getJson('/_kinetix/channels')->assertJsonCount(0, 'channels');
    }

    public function test_a_team_cannot_go_past_its_line_limit(): void
    {
        config(['kinetix.channels.limit' => 1]);
        $this->line();

        $this->actingAs($this->manager())
            ->postJson('/_kinetix/channels', ['provider' => 'whatsapp', 'name' => 'Second'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($this->manager())->getJson('/_kinetix/channels')->assertJsonPath('limit', 1);
    }

    public function test_deleting_a_line_removes_it_even_when_its_provider_is_down(): void
    {
        $channel                 = $this->line();
        $this->evolution->failOn = 'delete';

        $this->actingAs($this->manager())->deleteJson("/_kinetix/channels/{$channel->id}")->assertOk();

        $this->assertNull($channel->fresh());
    }

    public function test_unlinking_restarting_and_provider_errors(): void
    {
        $channel = $this->line(['status' => ChannelStatus::Connected]);
        $user    = $this->manager();

        $this->actingAs($user)->postJson("/_kinetix/channels/{$channel->id}/disconnect")->assertJsonPath('channel.status', 'disconnected');
        $this->assertCount(1, $this->evolution->callsTo('logout'));

        $this->evolution->failOn = 'restart';
        $this->actingAs($user)->postJson("/_kinetix/channels/{$channel->id}/restart")->assertStatus(502);
    }

    public function test_the_sync_command_records_lines_logged_out_from_the_phone(): void
    {
        $channel                = $this->line(['status' => ChannelStatus::Connected]);
        $this->evolution->state = 'close';

        $this->artisan('kinetix:channels:sync')->assertSuccessful();

        $this->assertSame(ChannelStatus::Disconnected, $channel->fresh()?->status);
    }

    public function test_status_changes_broadcast_when_turned_on(): void
    {
        config(['kinetix.channels.broadcast' => true]);
        Event::fake([ChannelUpdated::class]);

        $channel = $this->line();

        $this->actingAs($this->manager())->getJson('/_kinetix/channels')->assertJsonPath('broadcast', 'kinetix.channels');
        $this->hook($channel, ['event' => 'connection.update', 'data' => ['state' => 'open']]);

        Event::assertDispatched(ChannelUpdated::class, fn (ChannelUpdated $event): bool => $event->channelName === 'kinetix.channels'
            && $event->payload['status']                                                                       === 'connected' && $event->broadcastAs() === 'channel.updated');
    }
}
