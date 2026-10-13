<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\RouteFormAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class RouteFormUser extends Authenticatable
{
    protected $table = 'route_form_users';

    public $timestamps = false;

    protected $guarded = [];
}

class RouteFormPost extends Model
{
    protected $table = 'route_form_posts';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['secret'];
}

class RouteFormPostForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            TextInput::make('title')->required(),
            TextInput::make('body'),
        ];
    }
}

class RouteFormUpdatePostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['title' => 'required|max:100', 'body' => 'nullable|max:2000'];
    }
}

class RouteFormPostPolicy
{
    public function update(RouteFormUser $user, RouteFormPost $post): bool
    {
        return $post->title !== 'Locked';
    }
}

class RouteFormActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('route_form_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('secret')->nullable();
        });

        Schema::create('route_form_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        RouteFormPost::create(['title' => 'Mine', 'body' => 'Hello', 'secret' => 'x']);
        RouteFormPost::create(['title' => 'Locked', 'body' => 'No']);

        Gate::policy(RouteFormPost::class, RouteFormPostPolicy::class);
        $this->actingAs(RouteFormUser::create(['name' => 'Ann']));
    }

    private function table(): Table
    {
        return Table::make(RouteFormPost::query())
            ->recordActions([
                RouteFormAction::make('edit')
                    ->modalForm(RouteFormPostForm::class)
                    ->submitTo(fn (RouteFormPost $post): string => '/posts/'.$post->id, 'PUT'),
            ])
            ->toolbarActions([
                RouteFormAction::make('create')
                    ->modalForm(RouteFormPostForm::class)
                    ->submitTo('/posts'),
            ]);
    }

    public function test_a_row_submits_to_its_own_url_and_fetches_its_filled_form_on_open(): void
    {
        $data = $this->table()->toData();

        $edit = $data->records[0]->actions[0];
        $this->assertTrue($edit->isFormAction);
        $this->assertTrue($edit->formOnOpen);
        $this->assertSame(['url' => '/posts/1', 'method' => 'put'], $edit->submitTo);

        $this->postJson(route('kinetix.tables.form-action.form'), [
            'descriptor' => $data->formActionDescriptor,
            'action'     => 'edit',
            'recordId'   => 1,
        ])
            ->assertOk()
            ->assertJsonPath('form.schema.0.name', 'title')
            ->assertJsonPath('form.data.title', 'Mine')
            ->assertJsonPath('form.data.body', 'Hello')
            ->assertJsonMissingPath('form.data.secret');
    }

    public function test_the_form_is_only_served_where_the_user_may_write(): void
    {
        $data = $this->table()->toData();

        // The policy denies `update` on the locked row, so its form isn't
        // served (the endpoint checks `update` when the model has a policy).
        $this->postJson(route('kinetix.tables.form-action.form'), [
            'descriptor' => $data->formActionDescriptor,
            'action'     => 'edit',
            'recordId'   => 2,
        ])->assertForbidden();
    }

    public function test_authorize_hides_the_button_where_the_policy_denies(): void
    {
        $data = Table::make(RouteFormPost::query())
            ->recordActions([
                RouteFormAction::make('edit')
                    ->modalForm(RouteFormPostForm::class)
                    ->submitTo(fn (RouteFormPost $post): string => '/posts/'.$post->id, 'put')
                    ->authorize('update'),
            ])
            ->toData();

        $this->assertCount(1, $data->records[0]->actions);
        $this->assertSame([], $data->records[1]->actions);
    }

    public function test_a_toolbar_form_ships_inline_with_its_route(): void
    {
        $create = $this->table()->toData()->toolbarActions[0];

        $this->assertSame(['url' => '/posts', 'method' => 'post'], $create->submitTo);
        $this->assertSame('title', $create->form->toArray()['schema'][0]['name']);
    }

    public function test_where_it_submits_stays_on_the_table(): void
    {
        $payload = Crypt::decrypt((string) $this->table()->toData()->formActionDescriptor);

        $this->assertSame(
            [RouteFormAction::class.'::formClass' => RouteFormPostForm::class],
            $payload['forms']['record']['edit']['state'],
        );
    }

    public function test_kinetix_never_runs_it(): void
    {
        $data = $this->table()->toData();

        $this->postJson(route('kinetix.tables.form-action'), [
            'descriptor' => $data->formActionDescriptor,
            'action'     => 'create',
            'data'       => ['title' => 'New'],
        ])->assertStatus(405);

        $this->assertSame(2, RouteFormPost::query()->count());
    }

    public function test_it_needs_a_kinetix_form_and_a_write_method(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteFormAction::make('edit')->modalForm(RouteFormPost::class);
    }

    public function test_a_get_submission_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteFormAction::make('edit')->submitTo('/posts', 'get');
    }

    public function test_a_precognitive_form_is_served_precognitive(): void
    {
        $data = Table::make(RouteFormPost::query())
            ->recordActions([
                RouteFormAction::make('edit')
                    ->modalForm(RouteFormPostForm::class)
                    ->submitTo(fn (RouteFormPost $post): string => '/posts/'.$post->id, 'put')
                    ->precognitive(),
            ])
            ->toData();

        $this->postJson(route('kinetix.tables.form-action.form'), [
            'descriptor' => $data->formActionDescriptor,
            'action'     => 'edit',
            'recordId'   => 1,
        ])
            ->assertOk()
            ->assertJsonPath('form.precognitive', true)
            // The browser points it at the row's submit URL.
            ->assertJsonPath('form.validationUrl', null);
    }

    public function test_the_form_request_the_route_validates_with_can_be_the_form(): void
    {
        $data = Table::make(RouteFormPost::query())
            ->recordActions([
                RouteFormAction::make('edit')
                    ->modalForm(RouteFormUpdatePostRequest::class)
                    ->submitTo(fn (RouteFormPost $post): string => '/posts/'.$post->id, 'put'),
            ])
            ->toData();

        $response = $this->postJson(route('kinetix.tables.form-action.form'), [
            'descriptor' => $data->formActionDescriptor,
            'action'     => 'edit',
            'recordId'   => 1,
        ])->assertOk();

        $this->assertSame(['title', 'body'], array_column($response->json('form.schema'), 'name'));
        $this->assertSame('text-input', $response->json('form.schema.0.type'));
        $this->assertSame('textarea', $response->json('form.schema.1.type'));
        $this->assertSame(['title' => 'Mine', 'body' => 'Hello'], $response->json('form.data'));
    }
}
