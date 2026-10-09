<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\BulkAction;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;

class BulkActionUser extends Authenticatable
{
    protected $table = 'bulk_action_users';

    public $timestamps = false;

    protected $guarded = [];
}

class BulkWidgetRecord extends Model
{
    protected $table = 'bulk_widget_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['archived' => 'bool'];
}

/**
 * A server-side bulk action: archive every selected record. Name defaults to
 * the kebab of the class short name ('archive-selected').
 */
class ArchiveSelected extends BulkAction
{
    public function handle(Collection $records): void
    {
        $records->each(function (BulkWidgetRecord $record): void {
            $record->archived = true;
            $record->save();
        });
    }
}

enum BulkTagReason: string
{
    case Spam      = 'spam';
    case Duplicate = 'duplicate';
}

/**
 * A bulk action configured fluently on the table: a scalar, an enum, a model
 * and (for the failure case) a closure.
 */
class TagSelected extends BulkAction
{
    protected string $tag = 'none';

    protected ?BulkTagReason $reason = null;

    protected ?BulkWidgetRecord $mergeInto = null;

    protected ?Closure $transform = null;

    public function tag(string $tag): static
    {
        $this->tag = $tag;

        return $this;
    }

    public function reason(BulkTagReason $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function mergeInto(BulkWidgetRecord $record): static
    {
        $this->mergeInto = $record;

        return $this;
    }

    public function transformUsing(Closure $transform): static
    {
        $this->transform = $transform;

        return $this;
    }

    public function handle(Collection $records): void
    {
        $records->each(function (BulkWidgetRecord $record): void {
            $record->name = implode('|', [$this->tag, $this->reason?->value ?? '-', $this->mergeInto?->name ?? '-']);
            $record->save();
        });
    }
}

class BulkActionSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('bulk_widget_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('team')->default('a');
            $table->boolean('archived')->default(false);
        });

        Schema::create('bulk_action_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    private function descriptorFor(Table $table): string
    {
        return $table->toData()->bulkDescriptor ?? '';
    }

    public function test_a_registered_bulk_action_runs_over_selected_records(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);
        BulkWidgetRecord::create(['name' => 'B']);
        BulkWidgetRecord::create(['name' => 'C']);

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())
                ->bulkActions([ArchiveSelected::make()]),
        );

        $this->assertNotSame('', $descriptor);

        $response = $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $descriptor,
            'action'     => 'archive-selected',
            'ids'        => [1, 2],
        ]);

        $response->assertOk()->assertJsonPath('affected', 2);
        $this->assertTrue(BulkWidgetRecord::find(1)->archived);
        $this->assertTrue(BulkWidgetRecord::find(2)->archived);
        // The unselected record is untouched.
        $this->assertFalse(BulkWidgetRecord::find(3)->archived);
    }

    public function test_an_id_outside_the_table_scope_is_dropped(): void
    {
        // Table scoped to team 'a'; record 2 is in team 'b'.
        BulkWidgetRecord::create(['name' => 'A', 'team' => 'a']);
        BulkWidgetRecord::create(['name' => 'B', 'team' => 'b']);

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query()->where('team', 'a'))
                ->bulkActions([ArchiveSelected::make()]),
        );

        $response = $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $descriptor,
            'action'     => 'archive-selected',
            'ids'        => [1, 2], // 2 is out of scope
        ]);

        $response->assertOk()->assertJsonPath('affected', 1);
        $this->assertTrue(BulkWidgetRecord::find(1)->archived);
        // The out-of-scope record was never touched.
        $this->assertFalse(BulkWidgetRecord::find(2)->archived);
    }

    public function test_an_action_name_not_in_the_descriptor_is_rejected(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())
                ->bulkActions([ArchiveSelected::make()]),
        );

        $response = $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $descriptor,
            'action'     => 'delete-everything', // not registered
            'ids'        => [1],
        ]);

        $response->assertForbidden();
        $this->assertFalse(BulkWidgetRecord::find(1)->archived);
    }

    public function test_a_tampered_descriptor_is_rejected(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);

        $response = $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => 'not-a-valid-token',
            'action'     => 'archive-selected',
            'ids'        => [1],
        ]);

        $response->assertStatus(400);
        $this->assertFalse(BulkWidgetRecord::find(1)->archived);
    }

    public function test_each_record_is_authorized_by_the_policy(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);

        // A policy ability the table enforces via writeAbility(); deny it.
        Gate::define('bulk-archive', fn ($user, $record): bool => false);

        // Act as a user BEFORE minting the descriptor so its user-binding
        // matches the request (SignedDescriptor binds to auth()->id()).
        $this->actingAs(BulkActionUser::create(['name' => 'Bob']));

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())
                ->writeAbility('bulk-archive')
                ->bulkActions([ArchiveSelected::make()]),
        );

        $response = $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $descriptor,
            'action'     => 'archive-selected',
            'ids'        => [1],
        ]);

        $response->assertForbidden();
        $this->assertFalse(BulkWidgetRecord::find(1)->archived);
    }

    private function runBulk(string $descriptor, array $ids): TestResponse
    {
        return $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $descriptor,
            'action'     => 'archive-selected',
            'ids'        => $ids,
        ]);
    }

    public function test_the_actions_own_ability_is_enforced_per_record(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);
        BulkWidgetRecord::create(['name' => 'B']);
        Gate::define('archive-widget', fn ($user, BulkWidgetRecord $record): bool => $record->name !== 'B');
        $this->actingAs(BulkActionUser::create(['name' => 'Bob']));

        // The endpoint rebuilds the action from its class: before 0.207.1 the
        // fluent authorize() never reached it and B was archived.
        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())
                ->bulkActions([ArchiveSelected::make()->authorize('archive-widget')]),
        );

        $this->runBulk($descriptor, [1, 2])->assertForbidden();
        $this->assertFalse(BulkWidgetRecord::find(1)->archived);
        $this->assertFalse(BulkWidgetRecord::find(2)->archived);

        $this->runBulk($descriptor, [1])->assertOk();
        $this->assertTrue(BulkWidgetRecord::find(1)->archived);
    }

    public function test_the_actions_own_ability_replaces_the_tables_write_ability(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);
        Gate::define('bulk-archive', fn ($user, $record): bool => false);
        Gate::define('archive-widget', fn ($user, $record): bool => true);
        $this->actingAs(BulkActionUser::create(['name' => 'Bob']));

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())
                ->writeAbility('bulk-archive')
                ->bulkActions([ArchiveSelected::make()->authorize('archive-widget')]),
        );

        $this->runBulk($descriptor, [1])->assertOk();
        $this->assertTrue(BulkWidgetRecord::find(1)->archived);
    }

    public function test_a_hidden_bulk_action_is_not_sealed_when_a_visible_action_shares_its_name(): void
    {
        $data = Table::make(BulkWidgetRecord::query())
            ->bulkActions([
                Action::make('archive-selected')->label('Archive'),
                ArchiveSelected::make()->authorize(false),
            ])
            ->toData();

        $this->assertNull($data->bulkDescriptor);
    }

    public function test_a_record_dependent_visibility_closure_limits_the_records_it_runs_on(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);
        BulkWidgetRecord::create(['name' => 'B']);

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())
                ->bulkActions([
                    ArchiveSelected::make()->visible(fn (BulkWidgetRecord $record): bool => $record->name !== 'B'),
                ]),
        );

        $this->runBulk($descriptor, [1, 2])->assertForbidden();
        $this->assertFalse(BulkWidgetRecord::find(1)->archived);

        $this->runBulk($descriptor, [1])->assertOk();
        $this->assertTrue(BulkWidgetRecord::find(1)->archived);
    }

    public function test_a_record_independent_visibility_closure_is_evaluated(): void
    {
        $data = Table::make(BulkWidgetRecord::query())
            ->bulkActions([ArchiveSelected::make()->visible(fn (): bool => false)])
            ->toData();

        $this->assertSame([], $data->bulkActions);
        $this->assertNull($data->bulkDescriptor);
    }

    public function test_two_bulk_actions_with_the_same_name_are_a_configuration_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two bulk actions on this table are named [archive-selected]');

        Table::make(BulkWidgetRecord::query())
            ->bulkActions([ArchiveSelected::make(), ArchiveSelected::make()])
            ->toData();
    }

    public function test_a_descriptor_sealed_by_an_older_release_is_refused(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);

        // 0.197–0.207 sealed a bare name → class map, with no authorization.
        $descriptor = SignedDescriptor::seal([
            'model'    => BulkWidgetRecord::class,
            'bulk'     => ['archive-selected' => ArchiveSelected::class],
            'resource' => null,
            'scope'    => [],
            'relation' => null,
            'ability'  => null,
        ]);

        $this->runBulk($descriptor, [1])->assertForbidden();
        $this->assertFalse(BulkWidgetRecord::find(1)->archived);
    }

    public function test_fluent_configuration_reaches_the_endpoint(): void
    {
        BulkWidgetRecord::create(['name' => 'A']);
        $target = BulkWidgetRecord::create(['name' => 'Target']);

        $descriptor = $this->descriptorFor(
            Table::make(BulkWidgetRecord::query())->bulkActions([
                TagSelected::make()->tag('vip')->reason(BulkTagReason::Spam)->mergeInto($target),
            ]),
        );

        // A model travels as its identifier and is fetched again, as in a
        // queued job: the endpoint sees it as it is now.
        $target->update(['name' => 'Renamed']);

        $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $descriptor,
            'action'     => 'tag-selected',
            'ids'        => [1],
        ])->assertOk();

        $this->assertSame('vip|spam|Renamed', BulkWidgetRecord::find(1)->name);
    }

    public function test_only_settings_that_differ_from_a_fresh_instance_are_sealed(): void
    {
        $payload = Crypt::decrypt($this->descriptorFor(
            Table::make(BulkWidgetRecord::query())->bulkActions([TagSelected::make()->label('Tag')]),
        ));

        $this->assertSame([], $payload['bulk']['tag-selected']['state']);
    }

    public function test_a_setting_that_cant_reach_the_endpoint_fails_when_the_table_renders(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(TagSelected::class.'::transform');

        Table::make(BulkWidgetRecord::query())
            ->bulkActions([TagSelected::make()->transformUsing(fn (string $name): string => strtoupper($name))])
            ->toData();
    }
}
