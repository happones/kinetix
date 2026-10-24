<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelStatus;
use Happones\Kinetix\Channels\Drivers\TelegramDriver;
use Happones\Kinetix\Channels\Drivers\WhatsAppDriver;
use Happones\Kinetix\Channels\Events\ChannelMessageSent;
use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use Happones\Kinetix\Channels\InboundMessage;
use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\ConversationMessage;
use Happones\Kinetix\Inbox\Events\ConversationMessageCreated;
use Happones\Kinetix\Inbox\Events\ConversationReplied;
use Happones\Kinetix\Inbox\Events\InboxUpdated;
use Happones\Kinetix\Inbox\InboxManager;
use Happones\Kinetix\Inbox\MessageStatus;
use Happones\Kinetix\Inbox\QuickReply;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Fakes\FakeEvolutionGateway;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class InboxUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class InboxMembers
{
    /** @return iterable<InboxUser> */
    public static function all(): iterable
    {
        return InboxUser::query()->get();
    }
}

class InboxTest extends TestCase
{
    private FakeEvolutionGateway $evolution;

    private Channel $line;

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
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('auth.providers.users.model', InboxUser::class);
        $app['config']->set('kinetix.channels.enabled', true);
        $app['config']->set('kinetix.inbox.enabled', true);
        $app['config']->set('kinetix.inbox.members', [InboxMembers::class, 'all']);
    }

    protected function setUp(): void
    {
        parent::setUp();

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
            });
        }

        foreach (['role_has_permissions' => 'role_id', 'model_has_permissions' => 'model_id', 'model_has_roles' => 'model_id'] as $table => $key) {
            Schema::create($table, function ($blueprint) use ($table) {
                $blueprint->unsignedBigInteger($table === 'model_has_roles' ? 'role_id' : 'permission_id');
                $blueprint->unsignedBigInteger($table === 'role_has_permissions' ? 'role_id' : 'model_id');

                if ($table !== 'role_has_permissions') {
                    $blueprint->string('model_type');
                }
            });
        }

        (include __DIR__.'/../../database/migrations/2026_01_01_000041_create_kinetix_channels_table.php')->up();
        (include __DIR__.'/../../database/migrations/2026_01_01_000042_create_kinetix_inbox_tables.php')->up();
        (include __DIR__.'/../../database/migrations/2026_01_01_000044_create_kinetix_quick_replies_table.php')->up();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->evolution = new FakeEvolutionGateway;
        $this->app->instance(EvolutionGateway::class, $this->evolution);

        $this->line = Channel::create(['provider' => 'whatsapp', 'name' => 'Sales', 'external_id' => 'kinetix_sales', 'status' => ChannelStatus::Connected]);
    }

    /** @param list<string> $permissions */
    private function user(string $name, array $permissions = ['conversations.view']): InboxUser
    {
        $user = InboxUser::create(['name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function admin(): InboxUser
    {
        return $this->user('Admin', ['conversations.view', 'conversations.view_all']);
    }

    private function hook(array $payload, ?Channel $line = null): TestResponse
    {
        $line ??= $this->line;
        $header = $line->provider === 'telegram' ? TelegramDriver::SECRET_HEADER : WhatsAppDriver::SECRET_HEADER;

        return $this->postJson("/_kinetix/channels/webhook/{$line->public_id}", $payload, [$header => $line->webhook_secret]);
    }

    private function inbound(string $id, string $text, array $extra = [], ?Channel $line = null): TestResponse
    {
        return $this->hook(['event' => 'messages.upsert', 'data' => array_replace_recursive([
            'key'              => ['remoteJid' => '5215599999999@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName'         => 'Ada',
            'message'          => ['conversation' => $text],
            'messageType'      => 'conversation',
            'messageTimestamp' => 1760000000,
        ], $extra)], $line);
    }

    public function test_what_reaches_a_line_lands_in_one_conversation_once(): void
    {
        Event::fake([ConversationMessageCreated::class]);

        $this->inbound('A1', 'Hola')->assertOk();
        $this->inbound('A1', 'Hola')->assertOk();   // the provider repeats itself
        $this->inbound('A2', '¿Tienen envío?', ['pushName' => 'Ada Lovelace', 'message' => ['conversation' => null, 'extendedTextMessage' => ['text' => '¿Tienen envío?', 'contextInfo' => ['stanzaId' => 'A1']]], 'messageType' => 'extendedTextMessage'])->assertOk();

        $conversation = Conversation::query()->sole();

        $this->assertSame('5215599999999', $conversation->contact_key);
        $this->assertSame('Ada Lovelace', $conversation->contact_name);
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame('A1', $conversation->messages()->latest('id')->first()?->replyTo?->external_id);
        Event::assertDispatchedTimes(ConversationMessageCreated::class, 2);
    }

    public function test_an_inbound_file_is_fetched_onto_the_inbox_disk_and_streamed(): void
    {
        Storage::fake('local');

        $this->inbound('IMG1', '', ['message' => ['conversation' => null, 'imageMessage' => ['mimetype' => 'image/jpeg', 'caption' => 'Mi pedido']], 'messageType' => 'imageMessage']);

        $message = ConversationMessage::query()->sole();
        $this->assertSame('image', $message->type);
        $this->assertSame('Mi pedido', $message->body);
        $this->assertNotNull($message->media_path);
        Storage::disk('local')->assertExists((string) $message->media_path);
        $this->assertSame([['kinetix_sales', 'IMG1']], $this->evolution->callsTo('mediaBase64'));

        $this->actingAs($this->admin())
            ->get("/_kinetix/inbox/messages/{$message->id}/media")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_agents_see_only_the_lines_they_answer(): void
    {
        $other = Channel::create(['provider' => 'whatsapp', 'name' => 'Billing', 'external_id' => 'kinetix_billing', 'status' => ChannelStatus::Connected]);
        $this->inbound('A1', 'Hola');
        $this->hook(['event' => 'messages.upsert', 'data' => ['key' => ['remoteJid' => '5215511111111@s.whatsapp.net', 'id' => 'B1'], 'message' => ['conversation' => 'Factura']]], $other);

        $agent = $this->user('Agent');

        $this->actingAs($agent)->getJson('/_kinetix/inbox')->assertOk()->assertJsonCount(0, 'conversations')->assertJsonCount(0, 'channels');

        DB::table('kinetix_channel_agents')->insert(['channel_id' => $this->line->id, 'user_id' => $agent->id]);

        $this->actingAs($agent)->getJson('/_kinetix/inbox')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.channel.name', 'Sales')
            ->assertJsonPath('me.seesAll', false);

        $billing = Conversation::query()->where('channel_id', $other->id)->sole();
        $this->actingAs($agent)->getJson("/_kinetix/inbox/{$billing->id}/messages")->assertNotFound();

        $this->actingAs($this->admin())->getJson('/_kinetix/inbox')->assertJsonCount(2, 'conversations');
        $this->actingAs($this->user('Line manager', ['conversations.view', 'channels.manage']))->getJson('/_kinetix/inbox')->assertJsonCount(2, 'conversations');
        $this->actingAs($this->user('Outsider', []))->getJson('/_kinetix/inbox')->assertForbidden();
    }

    public function test_unread_is_counted_per_user(): void
    {
        $this->inbound('A1', 'Hola');
        $this->inbound('A2', '¿Siguen abiertos?');

        $ada   = $this->admin();
        $grace = $this->admin();
        $id    = Conversation::query()->sole()->id;

        $this->actingAs($ada)->getJson('/_kinetix/inbox')->assertJsonPath('conversations.0.unread', 2);
        $this->actingAs($ada)->postJson("/_kinetix/inbox/{$id}/read")->assertOk();

        $this->actingAs($ada)->getJson('/_kinetix/inbox')->assertJsonPath('conversations.0.unread', 0);
        $this->actingAs($ada)->getJson('/_kinetix/inbox?filter=unread')->assertJsonCount(0, 'conversations');
        $this->actingAs($grace)->getJson('/_kinetix/inbox?filter=unread')->assertJsonPath('conversations.0.unread', 2);
    }

    public function test_a_reply_goes_out_quoting_the_message_and_follows_its_receipts(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();
        $quoted       = ConversationMessage::query()->sole();

        $response = $this->actingAs($this->admin())
            ->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Sí, enviamos a todo el país.', 'reply_to' => $quoted->id])
            ->assertCreated()
            ->assertJsonPath('message.status', 'sent')
            ->assertJsonPath('message.direction', 'out')
            ->assertJsonPath('message.replyTo.body', 'Hola')
            ->assertJsonPath('message.authorName', 'Admin');

        $this->assertSame(['kinetix_sales', '5215599999999', 'Sí, enviamos a todo el país.', 'A1'], $this->evolution->callsTo('sendText')[0]);

        $sent = ConversationMessage::query()->findOrFail($response->json('message.id'));
        $this->assertNotNull($sent->external_id);

        $this->hook(['event' => 'messages.update', 'data' => ['keyId' => $sent->external_id, 'status' => 'READ']]);
        $this->hook(['event' => 'messages.update', 'data' => ['keyId' => $sent->external_id, 'status' => 'DELIVERY_ACK']]);
        $this->hook(['event' => 'messages.update', 'data' => ['keyId' => $sent->external_id, 'status' => 'ERROR']]);

        // Receipts arrive out of order; a message never moves back.
        $this->assertSame(MessageStatus::Read, $sent->fresh()?->status);
    }

    public function test_a_file_is_attached_within_the_accepted_types(): void
    {
        Storage::fake('local');
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();
        $admin        = $this->admin();

        $this->actingAs($admin)
            ->post("/_kinetix/inbox/{$conversation->id}/messages", ['attachment' => UploadedFile::fake()->create('cotizacion.pdf', 20, 'application/pdf'), 'body' => 'Tu cotización'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message.type', 'document')
            ->assertJsonPath('message.mediaName', 'cotizacion.pdf')
            ->assertJsonPath('message.mediaReady', true);

        [$instance, $to, $type, , $mime, $name, $caption] = $this->evolution->callsTo('sendMedia')[0];
        $this->assertSame(['kinetix_sales', '5215599999999', 'document', 'application/pdf', 'cotizacion.pdf', 'Tu cotización'], [$instance, $to, $type, $mime, $name, $caption]);

        $this->actingAs($admin)
            ->post("/_kinetix/inbox/{$conversation->id}/messages", ['attachment' => UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['attachment']);
    }

    public function test_a_refused_message_stays_in_the_thread_to_retry(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation            = Conversation::query()->sole();
        $admin                   = $this->admin();
        $this->evolution->failOn = 'sendText';

        $id = $this->actingAs($admin)
            ->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Hola'])
            ->assertCreated()
            ->assertJsonPath('message.status', 'failed')
            ->json('message.id');

        $this->evolution->failOn = null;

        $this->actingAs($admin)->postJson("/_kinetix/inbox/messages/{$id}/retry")->assertJsonPath('message.status', 'sent');
    }

    /** The line itself sent a message: from its phone (`messages.upsert`) or through the server (`send.message`). */
    private function lineSent(string $id, string $text, string $event = 'messages.upsert', string $to = '5215599999999'): TestResponse
    {
        return $this->hook(['event' => $event, 'data' => [
            'key'              => ['remoteJid' => "{$to}@s.whatsapp.net", 'fromMe' => true, 'id' => $id],
            'pushName'         => 'Acme Ventas',
            'message'          => ['conversation' => $text],
            'messageType'      => 'conversation',
            'messageTimestamp' => 1760000100,
        ]]);
    }

    public function test_what_the_line_sends_from_its_phone_shows_in_the_thread_once(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();

        $this->lineSent('P1', 'Te llamo en 5 minutos')->assertOk();
        $this->lineSent('P1', 'Te llamo en 5 minutos', 'send.message')->assertOk();

        $sent = ConversationMessage::query()->where('direction', 'out')->sole();
        $this->assertSame(['Te llamo en 5 minutos', 'P1', MessageStatus::Sent, null], [$sent->body, $sent->external_id, $sent->status, $sent->author_id]);

        // The line's own name never renames the contact.
        $this->assertSame('Ada', $conversation->fresh()?->contact_name);

        $admin = $this->admin();
        $this->actingAs($admin)->getJson("/_kinetix/inbox/{$conversation->id}/messages")
            ->assertJsonPath('messages.1.fromPhone', true)
            ->assertJsonPath('messages.1.bot', false);

        // Only what the contact wrote counts as unread.
        $this->actingAs($admin)->getJson("/_kinetix/inbox/{$conversation->id}")->assertJsonPath('conversation.unread', 1);

        // A chat the phone starts opens a conversation too.
        $this->lineSent('P2', '¿Recibiste el pedido?', to: '5215522222222');
        $this->assertSame(2, Conversation::query()->count());
    }

    public function test_a_bot_the_provider_runs_signs_its_answers(): void
    {
        $this->line->update(['meta' => ['bot' => ['integration' => 'typebot', 'id' => 'bot-1', 'name' => 'Asistente']]]);
        $this->inbound('A1', 'Hola');
        Event::fake([ConversationReplied::class]);

        $this->lineSent('B1', '¡Hola! ¿En qué te ayudo?', 'send.message');

        $conversation = Conversation::query()->sole();
        $this->actingAs($this->admin())->getJson("/_kinetix/inbox/{$conversation->id}/messages")
            ->assertJsonPath('messages.1.body', '¡Hola! ¿En qué te ayudo?')
            ->assertJsonPath('messages.1.bot', true)
            ->assertJsonPath('messages.1.authorName', 'Asistente')
            ->assertJsonPath('messages.1.fromPhone', false);

        // A bot answering isn't a person taking over.
        Event::assertNotDispatched(ConversationReplied::class);
    }

    public function test_on_a_bot_line_what_someone_types_on_the_phone_is_theirs_not_the_bots(): void
    {
        $this->line->update(['meta' => ['bot' => ['integration' => 'typebot', 'id' => 'bot-1', 'name' => 'Asistente']]]);
        $this->inbound('A1', 'Hola');
        Event::fake([ConversationReplied::class]);

        $this->lineSent('B1', '¡Hola! ¿En qué te ayudo?', 'send.message');
        Event::assertNotDispatched(ConversationReplied::class);

        $this->lineSent('P1', 'Soy Luis, te atiendo yo');

        $conversation = Conversation::query()->sole();
        $this->actingAs($this->admin())->getJson("/_kinetix/inbox/{$conversation->id}/messages")
            ->assertJsonPath('messages.1.bot', true)
            ->assertJsonPath('messages.2.body', 'Soy Luis, te atiendo yo')
            ->assertJsonPath('messages.2.bot', false)
            ->assertJsonPath('messages.2.authorName', null)
            ->assertJsonPath('messages.2.fromPhone', true);

        // A person took over from the phone: the bot steps back.
        Event::assertDispatched(ConversationReplied::class, fn (ConversationReplied $event): bool => $event->message->external_id === 'P1');
    }

    public function test_a_server_send_on_a_line_without_a_bot_is_unsigned(): void
    {
        $this->inbound('A1', 'Hola');
        Event::fake([ConversationReplied::class]);

        $this->lineSent('S1', 'Tu pedido salió hoy', 'send.message');

        $conversation = Conversation::query()->sole();
        $this->actingAs($this->admin())->getJson("/_kinetix/inbox/{$conversation->id}/messages")
            ->assertJsonPath('messages.1.body', 'Tu pedido salió hoy')
            ->assertJsonPath('messages.1.bot', false)
            ->assertJsonPath('messages.1.fromPhone', false);

        // Not a person answering, so a flow talking there keeps going.
        Event::assertNotDispatched(ConversationReplied::class);
    }

    public function test_the_inbox_own_sends_coming_back_are_not_doubled(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();
        $admin        = $this->admin();

        // Reported after the id was stored.
        $id = $this->actingAs($admin)->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Uno'])->json('message.id');
        $this->lineSent((string) ConversationMessage::query()->findOrFail($id)->external_id, 'Uno', 'send.message');

        // Reported while the send was still in flight.
        $this->evolution->onSend = function (string $to, string $text, string $externalId): void {
            ChannelMessageSent::dispatch($this->line, new InboundMessage(from: $to, fromName: null, text: $text, type: 'text', externalId: $externalId, isGroup: false, receivedAt: now(), raw: []));
        };
        $this->actingAs($admin)->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Dos'])->assertJsonPath('message.status', 'sent');
        $this->evolution->onSend = null;

        // Marked failed by a timeout, yet it went out.
        $this->evolution->failOn = 'sendText';
        $failed                  = $this->actingAs($admin)->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Tres'])->json('message.id');
        $this->evolution->failOn = null;
        $this->lineSent('X3', 'Tres', 'send.message');

        $this->assertSame(['Uno', 'Dos', 'Tres'], ConversationMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all());
        $this->assertSame([MessageStatus::Sent, 'X3', null], [
            ConversationMessage::query()->findOrFail($failed)->status,
            ConversationMessage::query()->findOrFail($failed)->external_id,
            ConversationMessage::query()->findOrFail($failed)->error,
        ]);
    }

    public function test_mirroring_the_lines_sends_can_be_turned_off(): void
    {
        config(['kinetix.inbox.mirror_sent' => false]);
        $this->inbound('A1', 'Hola');

        $this->lineSent('P1', 'Desde el teléfono');

        $this->assertSame(0, ConversationMessage::query()->where('direction', 'out')->count());
    }

    public function test_a_message_deleted_for_everyone_stays_marked(): void
    {
        $this->inbound('A1', 'Mi número es 55 1234 5678');
        $this->inbound('A2', 'Perdón, me equivoqué');

        // Taken back by the contact: a REVOKE notice on upsert…
        $this->hook(['event' => 'messages.upsert', 'data' => [
            'key'     => ['remoteJid' => '5215599999999@s.whatsapp.net', 'fromMe' => false, 'id' => 'REVOKE1'],
            'message' => ['protocolMessage' => ['type' => 'REVOKE', 'key' => ['id' => 'A1']]],
        ]])->assertOk();
        // …or the delete event, bare…
        $this->hook(['event' => 'messages.delete', 'data' => ['id' => 'A2', 'remoteJid' => '5215599999999@s.whatsapp.net']])->assertOk();
        // …or wrapped in `keys`, as some server builds send it.
        $this->inbound('A3', 'Y otra cosa');
        $this->hook(['event' => 'messages.delete', 'data' => ['keys' => [['id' => 'A3', 'remoteJid' => '5215599999999@s.whatsapp.net', 'fromMe' => false]]]])->assertOk();

        $this->assertSame(3, ConversationMessage::query()->count());
        $conversation = Conversation::query()->sole();
        $this->actingAs($this->admin())->getJson("/_kinetix/inbox/{$conversation->id}/messages")
            ->assertJsonPath('messages.0.body', 'Mi número es 55 1234 5678')
            ->assertJsonPath('messages.0.deletedAt', fn ($at) => is_string($at))
            ->assertJsonPath('messages.1.deletedAt', fn ($at) => is_string($at))
            ->assertJsonPath('messages.2.deletedAt', fn ($at) => is_string($at));
    }

    public function test_statuses_never_reach_the_inbox_and_groups_can_be_left_out(): void
    {
        $this->inbound('S1', 'Mi estado', ['key' => ['remoteJid' => 'status@broadcast']]);
        $this->inbound('G1', 'Hola grupo', ['key' => ['remoteJid' => '120363000000000000@g.us']]);
        $this->assertSame(1, Conversation::query()->count());

        config(['kinetix.channels.providers.whatsapp.direct_only' => true]);
        $this->inbound('G2', 'Otro', ['key' => ['remoteJid' => '120363000000000001@g.us']]);
        $this->inbound('N1', 'Boletín', ['key' => ['remoteJid' => '120363000000000002@newsletter']]);
        $this->inbound('A1', 'Hola');

        $this->assertSame(['120363000000000000@g.us', '5215599999999'], Conversation::query()->orderBy('id')->pluck('contact_key')->all());
    }

    public function test_the_business_writes_first_and_variables_are_filled_on_send(): void
    {
        config(['kinetix.inbox.variables' => [InboxTestVariables::class, 'for']]);
        Event::fake([ConversationReplied::class]);
        $inbox = app(InboxManager::class);

        $conversation = $inbox->conversationWith($this->line, '+52 1 55 3333 4444', 'Grace');
        $this->assertSame('5215533334444', $conversation->contact_key);
        $this->assertSame($conversation->id, $inbox->conversationWith($this->line, '5215533334444')->id);

        // A reminder from your code: no author, so no person took over.
        $message = $inbox->send($conversation, null, 'Hola {{contact.name}}, te escribe {{ negocio }} desde {{line.name}}. {{desconocida}}');

        $this->assertSame('Hola Grace, te escribe Acme desde Sales. {{desconocida}}', $message->body);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(['kinetix_sales', '5215533334444', 'Hola Grace, te escribe Acme desde Sales. {{desconocida}}', null], $this->evolution->callsTo('sendText')[0]);
        Event::assertNotDispatched(ConversationReplied::class);

        $agent = $this->admin();
        $this->actingAs($agent)->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Soy {{agent.name}}'])
            ->assertJsonPath('message.body', 'Soy Admin');
    }

    public function test_lines_a_user_should_not_see_stay_out_of_the_manager_and_the_inbox(): void
    {
        $personal = Channel::create(['provider' => 'whatsapp', 'name' => 'Personal de Grace', 'external_id' => 'kinetix_grace', 'status' => ChannelStatus::Connected, 'meta' => ['personal' => true]]);
        $this->inbound('A1', 'Hola');
        $this->inbound('P1', 'Hola Grace', [], $personal);
        config(['kinetix.channels.visible' => [InboxTestVariables::class, 'hidePersonal'], 'kinetix.channels.limit' => 1]);

        $admin = $this->user('Manager', ['conversations.view', 'conversations.view_all', 'channels.manage']);

        $this->actingAs($admin)->getJson('/_kinetix/channels')->assertJsonCount(1, 'channels')->assertJsonPath('channels.0.name', 'Sales');
        $this->actingAs($admin)->getJson("/_kinetix/channels/{$personal->id}/status")->assertNotFound();
        $this->assertSame(2, Conversation::query()->count());
        $this->actingAs($admin)->getJson('/_kinetix/inbox?filter=all')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.channel.name', 'Sales');

        // The personal line doesn't count against the plan: still one of one.
        $this->actingAs($admin)->postJson('/_kinetix/channels', ['provider' => 'whatsapp', 'name' => 'Otra'])
            ->assertJsonValidationErrors('name');
    }

    public function test_internal_notes_never_reach_the_contact(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();

        $this->actingAs($this->admin())
            ->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Cliente VIP, dar prioridad', 'note' => true])
            ->assertCreated()
            ->assertJsonPath('message.direction', 'note');

        $this->assertSame([], $this->evolution->callsTo('sendText'));

        // Nor does searching the inbox match them.
        $this->actingAs($this->admin())->getJson('/_kinetix/inbox?search=VIP')->assertJsonCount(0, 'conversations');
        $this->actingAs($this->admin())->getJson('/_kinetix/inbox?search=Ada')->assertJsonCount(1, 'conversations');
    }

    public function test_a_conversation_is_assigned_to_someone_who_sees_its_line(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();
        $admin        = $this->admin();
        $agent        = $this->user('Agent');
        $stranger     = $this->user('Stranger');
        DB::table('kinetix_channel_agents')->insert(['channel_id' => $this->line->id, 'user_id' => $agent->id]);

        $this->actingAs($admin)->getJson("/_kinetix/inbox/{$conversation->id}/assignees")
            ->assertJsonFragment(['name' => 'Agent'])
            ->assertJsonMissing(['name' => 'Stranger']);

        $this->actingAs($admin)->postJson("/_kinetix/inbox/{$conversation->id}/assign", ['user_id' => $stranger->id])->assertJsonValidationErrors(['user_id']);
        $this->actingAs($admin)->postJson("/_kinetix/inbox/{$conversation->id}/assign", ['user_id' => $agent->id])
            ->assertJsonPath('conversation.assignee.name', 'Agent');

        $this->actingAs($agent)->getJson('/_kinetix/inbox?filter=mine')->assertJsonCount(1, 'conversations');
        $this->actingAs($agent)->getJson('/_kinetix/inbox?filter=unassigned')->assertJsonCount(0, 'conversations');
    }

    public function test_an_archived_conversation_comes_back_when_the_contact_writes(): void
    {
        $this->inbound('A1', 'Hola');
        $conversation = Conversation::query()->sole();
        $admin        = $this->admin();

        $this->actingAs($admin)->postJson("/_kinetix/inbox/{$conversation->id}/archive")->assertJsonPath('conversation.archived', true);
        $this->actingAs($admin)->getJson('/_kinetix/inbox')->assertJsonCount(0, 'conversations');
        $this->actingAs($admin)->getJson('/_kinetix/inbox?filter=archived')->assertJsonCount(1, 'conversations');

        $this->inbound('A2', '¿Siguen ahí?');

        $this->actingAs($admin)->getJson('/_kinetix/inbox')->assertJsonCount(1, 'conversations');
    }

    public function test_a_thread_pages_back_and_catches_up(): void
    {
        config(['kinetix.inbox.messages_page_size' => 2]);

        foreach (['A1', 'A2', 'A3'] as $id) {
            $this->inbound($id, "Mensaje {$id}");
        }

        $conversation = Conversation::query()->sole();
        $admin        = $this->admin();

        $latest = $this->actingAs($admin)->getJson("/_kinetix/inbox/{$conversation->id}/messages")
            ->assertJsonPath('hasMore', true)
            ->assertJsonPath('messages.0.body', 'Mensaje A2')
            ->assertJsonPath('messages.1.body', 'Mensaje A3');

        $this->actingAs($admin)->getJson("/_kinetix/inbox/{$conversation->id}/messages?before=".$latest->json('messages.0.id'))
            ->assertJsonPath('hasMore', false)
            ->assertJsonPath('messages.0.body', 'Mensaje A1');

        $this->inbound('A4', 'Mensaje A4');

        $this->actingAs($admin)->getJson("/_kinetix/inbox/{$conversation->id}/messages?after=".$latest->json('messages.1.id'))
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Mensaje A4');
    }

    public function test_line_agents_are_managed_with_the_line(): void
    {
        $agent = $this->user('Agent');

        $this->actingAs($this->user('Agent 2'))->getJson("/_kinetix/channels/{$this->line->id}/agents")->assertForbidden();

        $manager = $this->user('Manager', ['channels.manage']);

        $this->actingAs($manager)->putJson("/_kinetix/channels/{$this->line->id}/agents", ['user_ids' => [$agent->id]])
            ->assertOk()
            ->assertJsonPath('agents', [$agent->id]);

        $this->actingAs($manager)->putJson("/_kinetix/channels/{$this->line->id}/agents", ['user_ids' => [999]])->assertJsonValidationErrors(['user_ids']);
    }

    public function test_telegram_replies_and_files_reach_the_inbox(): void
    {
        Storage::fake('local');
        Http::fake([
            'api.telegram.org/bot123:ABC/getFile'       => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]),
            'api.telegram.org/file/bot123:ABC/photos/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'api.telegram.org/bot123:ABC/sendMessage'   => Http::response(['ok' => true, 'result' => ['message_id' => 51]]),
        ]);

        $bot = Channel::create(['provider' => 'telegram', 'name' => 'Bot', 'status' => ChannelStatus::Connected, 'credentials' => ['token' => '123:ABC']]);

        $this->hook(['update_id' => 1, 'message' => [
            'message_id' => 7, 'date' => 1760000000,
            'chat'       => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242, 'first_name' => 'Grace'],
            'photo'      => [['file_id' => 'small'], ['file_id' => 'large']], 'caption' => 'Así llegó',
        ]], $bot)->assertOk();

        $message = ConversationMessage::query()->sole();
        $this->assertSame('image', $message->type);
        Storage::disk('local')->assertExists((string) $message->media_path);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/getFile') && $request['file_id'] === 'large');

        $this->actingAs($this->admin())
            ->postJson("/_kinetix/inbox/{$message->conversation_id}/messages", ['body' => 'Lo revisamos', 'reply_to' => $message->id])
            ->assertJsonPath('message.status', 'sent');

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage') && $request['reply_parameters']['message_id'] === 7);
        $this->assertSame('51', ConversationMessage::query()->latest('id')->first()?->external_id);
    }

    public function test_everyone_in_the_inbox_uses_quick_replies_and_managers_curate_them(): void
    {
        $agent   = $this->user('Agent');
        $manager = $this->user('Lead', ['conversations.view', 'conversations.quick_replies']);

        $this->actingAs($agent)->postJson('/_kinetix/inbox/replies', ['shortcut' => 'hola', 'body' => 'Hola'])->assertForbidden();

        $id = $this->actingAs($manager)
            ->postJson('/_kinetix/inbox/replies', ['shortcut' => '/Precios', 'body' => 'Hola {{contact.name}}, nuestra lista: https://acme.mx/precios'])
            ->assertCreated()
            ->assertJsonPath('reply.shortcut', 'precios')
            ->json('reply.id');

        // One word, once per team.
        $this->actingAs($manager)->postJson('/_kinetix/inbox/replies', ['shortcut' => 'precios', 'body' => 'otra'])->assertJsonValidationErrors(['shortcut']);
        $this->actingAs($manager)->postJson('/_kinetix/inbox/replies', ['shortcut' => 'dos palabras', 'body' => 'x'])->assertJsonValidationErrors(['shortcut']);

        $this->actingAs($agent)->getJson('/_kinetix/inbox/replies')
            ->assertOk()
            ->assertJsonPath('replies.0.shortcut', 'precios')
            ->assertJsonPath('canManage', false);

        $this->actingAs($manager)->putJson("/_kinetix/inbox/replies/{$id}", ['shortcut' => 'precios', 'body' => 'Lista nueva'])
            ->assertJsonPath('reply.body', 'Lista nueva');
        $this->actingAs($manager)->getJson('/_kinetix/inbox/replies')->assertJsonPath('canManage', true);

        // Another team's replies stay out of reach.
        $other = QuickReply::create(['team_id' => 7, 'shortcut' => 'ajena', 'body' => 'x']);
        $this->actingAs($manager)->deleteJson("/_kinetix/inbox/replies/{$other->id}")->assertNotFound();

        $this->actingAs($manager)->deleteJson("/_kinetix/inbox/replies/{$id}")->assertOk();
        $this->actingAs($agent)->getJson('/_kinetix/inbox/replies')->assertJsonCount(0, 'replies');
    }

    public function test_changes_broadcast_ids_only_on_the_lines_channel(): void
    {
        config(['kinetix.channels.broadcast' => true]);
        Event::fake([InboxUpdated::class]);

        $this->inbound('A1', 'Hola');

        Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event): bool => $event->channelName === "kinetix.inbox.{$this->line->id}"
            && array_keys($event->payload)                                                                 === ['conversationId', 'channelId', 'messageId']);
    }
}

class InboxTestVariables
{
    /** @return array<string, string> */
    public static function for(Conversation $conversation, mixed $author): array
    {
        return ['negocio' => 'Acme'];
    }

    public static function hidePersonal(Builder $lines, mixed $user): void
    {
        $lines->where(fn ($query) => $query->whereNull('meta')->orWhere('meta', 'not like', '%"personal":true%'));
    }
}
