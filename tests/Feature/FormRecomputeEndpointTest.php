<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Support\Get;
use Happones\Kinetix\Forms\Support\Set;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * A `Form` subclass is reconstructible, so it opts into the reactivity loop:
 * its toData() seals a recompute descriptor, and the signed endpoint rebuilds
 * it to return a schema recomputed against the in-flight state.
 */
class ReactiveCountryForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Select::make('country')
                ->live()
                ->options(['es' => 'Spain', 'fr' => 'France'])
                ->afterStateUpdated(fn (Set $set) => $set('state', null)),
            Select::make('state')
                ->options(fn (Get $get) => match ($get('country')) {
                    'es'    => ['mad' => 'Madrid'],
                    'fr'    => ['par' => 'Paris'],
                    default => [],
                }),
        ];
    }
}

class RcAuthor extends Model
{
    protected $table = 'rc_authors';

    public $timestamps = false;

    protected $guarded = [];
}

class RcPost extends Model
{
    protected $table = 'rc_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(RcAuthor::class, 'author_id');
    }
}

class RcPostResource extends Resource
{
    public static function getModel(): string
    {
        return RcPost::class;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('author_id')->relationship('author', 'name'),
            Select::make('kind')->live()->options(['a' => 'A']),
        ]);
    }
}

class FormRecomputeEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The endpoint is throttled, and throttling needs a cache store.
        $app['config']->set('cache.default', 'array');
    }

    private function descriptor(): string
    {
        return ReactiveCountryForm::make()->toData()->recomputeDescriptor ?? '';
    }

    public function test_a_form_subclass_with_a_live_field_ships_a_recompute_descriptor(): void
    {
        $this->assertNotSame('', $this->descriptor());

        // A plain inline form with no live field ships none.
        $plain = Form::make()->schema([Select::make('x')->options(['a' => 'A'])]);
        $this->assertNull($plain->toData()->recomputeDescriptor);
    }

    public function test_the_endpoint_returns_the_recomputed_schema(): void
    {
        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => $this->descriptor(),
            'data'       => ['country' => 'fr'],
        ]);

        $response->assertOk();

        $schema = $response->json('schema');
        $byName = [];
        foreach ($schema as $field) {
            $byName[$field['name']] = $field;
        }

        $this->assertSame(['par' => 'Paris'], $byName['state']['options']);
    }

    public function test_the_endpoint_returns_after_state_updated_changes(): void
    {
        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => $this->descriptor(),
            'data'       => ['country' => 'fr', 'state' => 'mad'],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('changes.state'));
    }

    public function test_a_tampered_descriptor_is_rejected(): void
    {
        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => 'not-a-valid-token',
            'data'       => ['country' => 'fr'],
        ]);

        $response->assertStatus(400);
    }

    /**
     * A create form is built around a fresh model (new RcPost). The endpoint
     * rebuilt it around null, so the relationship Select had no model to
     * resolve and its options vanished after the first live change.
     */
    public function test_a_create_form_keeps_its_relationship_options_after_a_recompute(): void
    {
        Schema::create('rc_authors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('rc_posts', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
        });
        RcAuthor::create(['name' => 'Ada']);

        $descriptor = RcPostResource::form(Form::make(new RcPost)->operation('create'))
            ->reactiveVia(RcPostResource::class)
            ->toData()
            ->recomputeDescriptor;

        $response = $this->postJson(route('kinetix.forms.recompute'), [
            'descriptor' => $descriptor,
            'data'       => ['kind' => 'a'],
            'changed'    => ['kind'],
        ]);

        $response->assertOk();

        $byName = [];
        foreach ($response->json('schema') as $field) {
            $byName[$field['name']] = $field;
        }

        $this->assertSame([1 => 'Ada'], $byName['author_id']['options']);
    }

    /**
     * Every debounced keystroke in a live field rebuilds the form. The limit
     * counts on its own: unprefixed, it shared one counter with every plain
     * `throttle:N,M` route of the host.
     */
    public function test_the_endpoint_is_rate_limited(): void
    {
        $middleware = Route::getRoutes()->getByName('kinetix.forms.recompute')->gatherMiddleware();

        $this->assertContains('throttle:120,1,kinetix-recompute', $middleware);
    }
}
