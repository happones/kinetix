<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\CarbonImmutable;
use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelStatus;
use Happones\Kinetix\Channels\Drivers\TelegramDriver;
use Happones\Kinetix\Channels\Drivers\WhatsAppDriver;
use Happones\Kinetix\Channels\Evolution\EvolutionGateway;
use Happones\Kinetix\Flows\Flow;
use Happones\Kinetix\Flows\FlowRun;
use Happones\Kinetix\Flows\FlowRunStatus;
use Happones\Kinetix\Inbox\Conversation;
use Happones\Kinetix\Inbox\ConversationMessage;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Tests\Fakes\FakeEvolutionGateway;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

class FlowsTest extends TestCase
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
        $app['config']->set('kinetix.flows.enabled', true);
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

        foreach (['role_has_permissions', 'model_has_permissions', 'model_has_roles'] as $table) {
            Schema::create($table, function ($blueprint) use ($table) {
                $blueprint->unsignedBigInteger($table === 'model_has_roles' ? 'role_id' : 'permission_id');
                $blueprint->unsignedBigInteger($table === 'role_has_permissions' ? 'role_id' : 'model_id');

                if ($table !== 'role_has_permissions') {
                    $blueprint->string('model_type');
                }
            });
        }

        foreach (['000041_create_kinetix_channels_table', '000042_create_kinetix_inbox_tables', '000043_create_kinetix_flows_tables'] as $migration) {
            (include __DIR__."/../../database/migrations/2026_01_01_{$migration}.php")->up();
        }

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->evolution = new FakeEvolutionGateway;
        $this->app->instance(EvolutionGateway::class, $this->evolution);

        $this->line = Channel::create(['provider' => 'whatsapp', 'name' => 'Sales', 'external_id' => 'kinetix_sales', 'status' => ChannelStatus::Connected]);
    }

    private function builder(): InboxUser
    {
        $user = InboxUser::create(['name' => 'Builder']);
        $user->givePermissionTo(['flows.manage', 'conversations.view', 'conversations.view_all']);

        return $user;
    }

    /**
     * A published flow: `nodes` [id => [type, data]] chained in order through
     * `next` unless `edges` says otherwise.
     *
     * @param array<string, array{0: string, 1?: array<string, mixed>}> $nodes
     * @param list<array{0: string, 1: string, 2: string}>|null         $edges   [source, handle, target]
     * @param array<string, mixed>                                      $trigger
     */
    private function flow(array $nodes, ?array $edges = null, array $trigger = ['type' => 'keyword', 'keywords' => ['hola']]): Flow
    {
        $graphNodes = [['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []]];

        foreach ($nodes as $id => $node) {
            $graphNodes[] = ['id' => $id, 'type' => $node[0], 'position' => ['x' => 0, 'y' => 0], 'data' => $node[1] ?? []];
        }

        if ($edges === null) {
            $ids   = ['start', ...array_keys($nodes)];
            $edges = [];

            for ($i = 0; $i < count($ids) - 1; $i++) {
                $edges[] = [$ids[$i], 'next', $ids[$i + 1]];
            }
        }

        $graph = [
            'nodes' => $graphNodes,
            'edges' => array_map(static fn (array $edge): array => ['id' => implode('-', $edge), 'source' => $edge[0], 'sourceHandle' => $edge[1], 'target' => $edge[2]], $edges),
        ];

        $flow    = Flow::create(['name' => 'Bot', 'status' => 'active', 'trigger' => $trigger, 'draft' => $graph]);
        $version = $flow->versions()->create(['number' => 1, 'graph' => $graph]);
        $flow->update(['published_version_id' => $version->id]);

        return $flow;
    }

    private function say(string $text, string $id, ?Channel $line = null, string $from = '5215599999999'): TestResponse
    {
        $line ??= $this->line;

        return $this->postJson("/_kinetix/channels/webhook/{$line->public_id}", ['event' => 'messages.upsert', 'data' => [
            'key'      => ['remoteJid' => "{$from}@s.whatsapp.net", 'fromMe' => false, 'id' => $id],
            'pushName' => 'Ada',
            'message'  => ['conversation' => $text],
        ]], [WhatsAppDriver::SECRET_HEADER => $line->webhook_secret]);
    }

    /** @return list<string> what the bot sent */
    private function botSaid(): array
    {
        return array_map(static fn (array $call): string => $call[2], $this->evolution->callsTo('sendText'));
    }

    private function latestRun(): FlowRun
    {
        return FlowRun::query()->latest('id')->firstOrFail();
    }

    public function test_a_keyword_starts_the_flow_and_the_bot_answers_in_the_conversation(): void
    {
        $this->flow(['hi' => ['message', ['text' => '¡Hola {{contact.name}}! Bienvenida a {{line.name}}.']]]);

        $this->say('Hola, buen día', 'A1')->assertOk();

        $this->assertSame(['¡Hola Ada! Bienvenida a Sales.'], $this->botSaid());
        $this->assertSame(FlowRunStatus::Completed, $this->latestRun()->status);

        // The bot's message is in the thread, signed by the flow.
        $bot = ConversationMessage::query()->where('direction', 'out')->sole();
        $this->assertTrue($bot->meta['bot']);
        $this->assertSame('Bot', $bot->meta['author_name']);

        // Other words don't start it.
        $this->say('Gracias', 'A2', from: '5215511111111');
        $this->assertCount(1, $this->botSaid());
    }

    public function test_a_question_waits_validates_and_branches(): void
    {
        $this->flow(
            [
                'ask'   => ['question', ['text' => '¿Tu correo?', 'variable' => 'email', 'validate' => 'email', 'retry' => 'Ese correo no parece válido.']],
                'check' => ['condition', ['match' => 'all', 'rules' => [['variable' => 'email', 'operator' => 'contains', 'value' => '@empresa.com']]]],
                'vip'   => ['message', ['text' => 'Cliente empresarial: {{email}}']],
                'basic' => ['message', ['text' => 'Gracias, {{email}}']],
            ],
            [['start', 'next', 'ask'], ['ask', 'next', 'check'], ['check', 'yes', 'vip'], ['check', 'no', 'basic']],
        );

        $this->say('hola', 'A1');
        $this->assertSame(FlowRunStatus::WaitingReply, $this->latestRun()->status);

        $this->say('no tengo', 'A2');
        $this->say('ADA@empresa.com', 'A3');

        $this->assertSame(['¿Tu correo?', 'Ese correo no parece válido.', 'Cliente empresarial: ada@empresa.com'], $this->botSaid());
        $this->assertSame('ada@empresa.com', $this->latestRun()->variables['email']);
        $this->assertSame(FlowRunStatus::Completed, $this->latestRun()->status);
    }

    public function test_options_go_as_a_numbered_menu_and_a_number_or_the_label_picks(): void
    {
        $this->flow(
            [
                'menu'  => ['options', ['text' => '¿Qué necesitas?', 'variable' => 'topic', 'options' => [['id' => 'sales', 'label' => 'Ventas'], ['id' => 'support', 'label' => 'Soporte técnico']]]],
                'sales' => ['message', ['text' => 'Te paso con ventas']],
                'tech'  => ['message', ['text' => 'Soporte: {{topic}}']],
            ],
            [['start', 'next', 'menu'], ['menu', 'sales', 'sales'], ['menu', 'support', 'tech']],
        );

        $this->say('hola', 'A1');
        $this->assertSame("¿Qué necesitas?\n\n1. Ventas\n2. Soporte técnico", $this->botSaid()[0]);

        $this->say('soporte tecnico', 'A2');
        $this->assertSame('Soporte: Soporte técnico', $this->botSaid()[1]);

        // A number works too (a new contact).
        $this->say('hola', 'B1', from: '5215511111111');
        $this->say('1', 'B2', from: '5215511111111');
        $this->assertSame('Te paso con ventas', $this->botSaid()[3]);
    }

    public function test_telegram_offers_options_as_buttons_and_a_tap_answers(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);
        $bot = Channel::create(['provider' => 'telegram', 'name' => 'Bot', 'status' => ChannelStatus::Connected, 'credentials' => ['token' => '123:ABC']]);

        $this->flow(
            [
                'menu' => ['options', ['text' => '¿Qué necesitas?', 'options' => [['id' => 'a', 'label' => 'Precios'], ['id' => 'b', 'label' => 'Horarios']]]],
                'b'    => ['message', ['text' => 'Abrimos de 9 a 18']],
            ],
            [['start', 'next', 'menu'], ['menu', 'b', 'b']],
            ['type' => 'any_message'],
        );

        $hook = fn (array $update) => $this->postJson("/_kinetix/channels/webhook/{$bot->public_id}", $update, [TelegramDriver::SECRET_HEADER => $bot->webhook_secret]);

        $hook(['update_id' => 1, 'message' => ['message_id' => 1, 'date' => 1760000000, 'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42, 'first_name' => 'Grace'], 'text' => 'hey']]);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['text']                                          === '¿Qué necesitas?'
            && $request['reply_markup']['inline_keyboard'][1][0]['text'] === 'Horarios');

        $hook(['update_id' => 2, 'callback_query' => ['id' => 'cb1', 'from' => ['id' => 42, 'first_name' => 'Grace'], 'message' => ['chat' => ['id' => 42, 'type' => 'private']], 'data' => 'Horarios']]);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage') && $request['text'] === 'Abrimos de 9 a 18');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery'));
    }

    public function test_a_delay_waits_and_the_scheduler_resumes_it(): void
    {
        $this->flow([
            'wait'  => ['delay', ['amount' => 2, 'unit' => 'hours']],
            'later' => ['message', ['text' => '¿Pudiste revisar la cotización?']],
        ]);

        $this->say('hola', 'A1');

        $this->assertSame(FlowRunStatus::WaitingDelay, $this->latestRun()->status);
        $this->assertSame([], $this->botSaid());

        $this->travel(3)->hours();
        $this->artisan('kinetix:flows:resume')->assertSuccessful();

        $this->assertSame(['¿Pudiste revisar la cotización?'], $this->botSaid());
        $this->assertSame(FlowRunStatus::Completed, $this->latestRun()->status);
    }

    public function test_a_handoff_assigns_notes_and_stops_and_assigned_conversations_stay_with_people(): void
    {
        $agent = InboxUser::create(['name' => 'Agent']);

        $this->flow(['human' => ['handoff', ['assign_to' => $agent->id, 'note' => 'Quiere hablar con alguien: {{message}}']]], trigger: ['type' => 'any_message']);

        $this->say('quiero hablar con alguien', 'A1');

        $conversation = Conversation::query()->sole();
        $this->assertSame($agent->id, (int) $conversation->assigned_to);
        $this->assertSame(FlowRunStatus::HandedOff, $this->latestRun()->status);
        $this->assertSame('Quiere hablar con alguien: quiero hablar con alguien', ConversationMessage::query()->where('direction', 'note')->sole()->body);

        // Assigned now: the bot stays out.
        $this->say('¿hola?', 'A2');
        $this->assertSame(1, FlowRun::query()->count());
    }

    public function test_a_person_answering_pauses_the_bot(): void
    {
        $this->flow(['ask' => ['question', ['text' => '¿Tu nombre?', 'variable' => 'name']]]);
        $this->say('hola', 'A1');

        $conversation = Conversation::query()->sole();

        $this->actingAs($this->builder())
            ->postJson("/_kinetix/inbox/{$conversation->id}/messages", ['body' => 'Hola Ada, soy Grace'])
            ->assertCreated();

        $this->assertSame(FlowRunStatus::Paused, $this->latestRun()->status);

        // Her answer now goes to the person, not the bot.
        $this->say('Ada', 'A2');
        $this->assertSame(['¿Tu nombre?', 'Hola Ada, soy Grace'], $this->botSaid());
    }

    public function test_a_running_conversation_finishes_on_the_version_it_started(): void
    {
        $flow = $this->flow([
            'ask' => ['question', ['text' => '¿Tu nombre?', 'variable' => 'name']],
            'bye' => ['message', ['text' => 'Versión 1, {{name}}']],
        ]);

        $this->say('hola', 'A1');

        // Edited and published while Ada is answering.
        $draft                             = $flow->draft;
        $draft['nodes'][2]['data']['text'] = 'Versión 2, {{name}}';
        $flow->update(['draft' => $draft]);

        $this->actingAs($this->builder())->postJson("/_kinetix/flows/{$flow->id}/publish")->assertOk()->assertJsonPath('flow.version', 2);

        $this->say('Ada', 'A2');
        $this->assertSame('Versión 1, Ada', $this->botSaid()[1]);

        $this->say('hola', 'B1', from: '5215511111111');
        $this->say('Grace', 'B2', from: '5215511111111');
        $this->assertSame('Versión 2, Grace', $this->botSaid()[3]);
    }

    public function test_a_draft_with_problems_cannot_be_published(): void
    {
        $builder = $this->builder();

        $id = $this->actingAs($builder)->postJson('/_kinetix/flows', ['name' => 'Bienvenida'])
            ->assertCreated()
            ->assertJsonPath('flow.status', 'draft')
            ->assertJsonPath('flow.problems', [])
            ->json('flow.id');

        $this->actingAs($builder)->putJson("/_kinetix/flows/{$id}", ['graph' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'msg', 'type' => 'message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => '']],
            ],
            'edges' => [['id' => 'e', 'source' => 'start', 'sourceHandle' => 'next', 'target' => 'msg']],
        ]])->assertJsonPath('flow.problems.0.node', 'msg');

        $this->actingAs($builder)->postJson("/_kinetix/flows/{$id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('problems.0.node', 'msg');

        $this->actingAs(InboxUser::create(['name' => 'Agent']))->getJson('/_kinetix/flows')->assertForbidden();
    }

    public function test_the_simulator_runs_the_unsaved_graph_without_sending_anything(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response(['order' => ['status' => 'shipped']])]);
        $flow = $this->flow(['x' => ['message', ['text' => 'saved']]]);

        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => []],
                ['id' => 'ask', 'type' => 'question', 'data' => ['text' => '¿Número de pedido?', 'variable' => 'order_id', 'validate' => 'number']],
                ['id' => 'api', 'type' => 'http', 'data' => ['method' => 'GET', 'url' => 'https://93.184.216.34/orders/{{order_id}}', 'save' => [['variable' => 'state', 'path' => 'order.status']]]],
                ['id' => 'wait', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']],
                ['id' => 'tell', 'type' => 'message', 'data' => ['text' => 'Tu pedido {{order_id}} está: {{state}}']],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'ask'], ['source' => 'ask', 'target' => 'api'],
                ['source'                                           => 'api', 'target' => 'wait'], ['source' => 'wait', 'target' => 'tell'],
            ],
        ];

        $builder = $this->builder();
        $first   = $this->actingAs($builder)->postJson("/_kinetix/flows/{$flow->id}/simulate", ['graph' => $graph])
            ->assertOk()
            ->assertJsonPath('status', 'waiting_reply')
            ->assertJsonPath('node', 'ask')
            ->assertJsonPath('entries.0.text', '¿Número de pedido?');

        $this->actingAs($builder)->postJson("/_kinetix/flows/{$flow->id}/simulate", ['graph' => $graph, 'state' => $first->json('state'), 'input' => '1042'])
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('entries.0.kind', 'system')     // the delay, passed at once
            ->assertJsonPath('entries.1.text', 'Tu pedido 1042 está: shipped')
            ->assertJsonPath('path', ['api', 'wait', 'tell']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://93.184.216.34/orders/1042');
        $this->assertSame([], $this->botSaid());
        $this->assertSame(0, FlowRun::query()->count());
    }

    public function test_an_http_node_refuses_private_addresses(): void
    {
        $flow  = $this->flow(['x' => ['message', ['text' => 'saved']]]);
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'], ['id' => 'api', 'type' => 'http', 'data' => ['url' => 'http://169.254.169.254/latest/meta-data']],
                ['id'                                       => 'ok', 'type' => 'message', 'data' => ['text' => 'ok']], ['id' => 'err', 'type' => 'message', 'data' => ['text' => 'No pude consultar']],
            ],
            'edges' => [['source' => 'start', 'target' => 'api'], ['source' => 'api', 'target' => 'ok'], ['source' => 'api', 'sourceHandle' => 'error', 'target' => 'err']],
        ];

        $this->actingAs($this->builder())->postJson("/_kinetix/flows/{$flow->id}/simulate", ['graph' => $graph])
            ->assertJsonPath('entries.0.text', 'No pude consultar');
    }

    public function test_business_hours_branch_open_or_closed(): void
    {
        $this->flow(
            [
                'hours'  => ['hours', ['timezone' => 'America/Mexico_City', 'schedule' => ['monday' => ['enabled' => true, 'ranges' => [['start' => '09:00', 'end' => '18:00']]]]]],
                'open'   => ['message', ['text' => 'Te atendemos en seguida']],
                'closed' => ['message', ['text' => 'Estamos cerrados']],
            ],
            [['start', 'next', 'hours'], ['hours', 'open', 'open'], ['hours', 'closed', 'closed']],
            ['type' => 'any_message'],
        );

        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00', 'America/Mexico_City'));   // a Monday
        $this->say('hola', 'A1');

        Carbon::setTestNow(Carbon::parse('2026-10-18 10:00', 'America/Mexico_City'));   // a Sunday
        $this->say('hola', 'B1', from: '5215511111111');

        $this->assertSame(['Te atendemos en seguida', 'Estamos cerrados'], $this->botSaid());
    }

    public function test_apps_on_immutable_dates_run_flows_and_waits(): void
    {
        Date::use(CarbonImmutable::class);

        try {
            $this->flow(['wait' => ['delay', ['amount' => 1, 'unit' => 'minutes']], 'hi' => ['message', ['text' => 'Listo']]]);

            $this->say('hola', 'A1')->assertOk();
            $this->assertSame(FlowRunStatus::WaitingDelay, $this->latestRun()->status);

            $this->travel(2)->minutes();
            $this->artisan('kinetix:flows:resume')->assertSuccessful();
            $this->assertSame(['Listo'], $this->botSaid());
        } finally {
            Date::useDefault();
        }
    }

    public function test_a_first_message_trigger_only_greets_new_contacts(): void
    {
        $this->flow(['hi' => ['message', ['text' => 'Bienvenida']]], trigger: ['type' => 'first_message']);

        $this->say('hola', 'A1');
        $this->say('otra cosa', 'A2');

        $this->assertSame(['Bienvenida'], $this->botSaid());
    }

    public function test_the_inbox_starts_and_stops_a_flow(): void
    {
        $flow = $this->flow(['ask' => ['question', ['text' => 'Califica la atención del 1 al 5', 'variable' => 'score', 'validate' => 'number']]], trigger: ['type' => 'manual']);
        $this->say('gracias por todo', 'A1');

        $conversation = Conversation::query()->sole();
        $builder      = $this->builder();

        $this->actingAs($builder)->getJson("/_kinetix/inbox/{$conversation->id}/flows")
            ->assertJsonPath('flows.0.name', 'Bot')
            ->assertJsonPath('run', null);

        $this->actingAs($builder)->postJson("/_kinetix/inbox/{$conversation->id}/flows/{$flow->id}")
            ->assertJsonPath('run.status', 'waiting_reply');
        $this->assertSame(['Califica la atención del 1 al 5'], $this->botSaid());

        $this->actingAs($builder)->postJson("/_kinetix/inbox/{$conversation->id}/flows/stop")->assertJsonPath('run', null);
        $this->assertSame(FlowRunStatus::Paused, $this->latestRun()->status);
    }

    public function test_stats_count_conversations_per_node(): void
    {
        $flow = $this->flow([
            'ask' => ['question', ['text' => '¿Tu nombre?', 'variable' => 'name']],
            'bye' => ['message', ['text' => 'Gracias']],
        ]);

        $this->say('hola', 'A1');
        $this->say('Ada', 'A2');
        $this->say('hola', 'B1', from: '5215511111111');

        $this->actingAs($this->builder())->getJson("/_kinetix/flows/{$flow->id}/stats")
            ->assertJsonPath('runs', 2)
            ->assertJsonPath('nodes.ask.entered', 2)
            ->assertJsonPath('nodes.ask.waiting', 1)
            ->assertJsonPath('nodes.bye.entered', 1)
            ->assertJsonPath('nodes.bye.completed', 1);
    }
}
