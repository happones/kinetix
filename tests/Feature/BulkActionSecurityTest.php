<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\BulkAction;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

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
}
