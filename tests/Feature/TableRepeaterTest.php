<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\TableRepeater;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class TROrder extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(TROrderItem::class, 'order_id');
    }
}

class TROrderItem extends Model
{
    protected $table = 'order_items';

    public $timestamps = false;

    protected $guarded = [];
}

class TRUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class TableRepeaterTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TRUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id');
            $table->string('name')->nullable();
            $table->integer('qty')->nullable();
            $table->string('secret')->nullable();
        });
    }

    private function field(): TableRepeater
    {
        return TableRepeater::make('items')
            ->relationship('items')
            ->autosave()
            ->columns([
                TextInput::make('name'),
                NumberField::make('qty'),
            ])
            ->summarize(['qty' => 'sum'])
            ->exportable();
    }

    public function test_it_serializes_as_a_table_repeater_with_summaries(): void
    {
        $order = TROrder::create([]);

        $data = $this->field()->toData('edit', $order);

        $this->assertSame('table-repeater', $data->type);
        $this->assertCount(2, $data->schema);
        $this->assertSame(['qty' => 'sum'], $data->summarize);
        $this->assertTrue($data->exportable);
        $this->assertTrue($data->autosave);
        $this->assertNotNull($data->autosaveToken);

        $payload = Crypt::decrypt($data->autosaveToken);
        $this->assertSame(TROrder::class, $payload['parent']);
        $this->assertSame('items', $payload['relation']);
        $this->assertSame(['name', 'qty'], $payload['columns']);
    }

    public function test_no_autosave_token_without_relationship_or_record(): void
    {
        // No record → no token.
        $this->assertNull(TableRepeater::make('items')->autosave()->relationship('items')
            ->columns([TextInput::make('name')])->toData('create', null)?->autosaveToken);
    }

    public function test_autosave_create_writes_only_allowlisted_columns(): void
    {
        $order = TROrder::create([]);
        $token = $this->field()->toData('edit', $order)->autosaveToken;

        $this->actingAs(TRUser::create([]))
            ->postJson('/_kinetix/tables/table-repeater', [
                'token'  => $token,
                'values' => ['name' => 'Widget', 'qty' => 3, 'secret' => 'hack'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $item = $order->items()->first();
        $this->assertSame('Widget', $item->name);
        $this->assertSame(3, (int) $item->qty);
        $this->assertNull($item->secret); // not in the allowlist
    }

    public function test_autosave_update_and_delete(): void
    {
        $order = TROrder::create([]);
        $token = $this->field()->toData('edit', $order)->autosaveToken;
        $item  = $order->items()->create(['name' => 'Old', 'qty' => 1]);
        $user  = TRUser::create([]);

        $this->actingAs($user)
            ->putJson('/_kinetix/tables/table-repeater', [
                'token'  => $token,
                'id'     => $item->getKey(),
                'values' => ['qty' => 9],
            ])
            ->assertOk();
        $this->assertSame(9, (int) $item->fresh()->qty);

        $this->actingAs($user)
            ->deleteJson('/_kinetix/tables/table-repeater', [
                'token' => $token,
                'id'    => $item->getKey(),
            ])
            ->assertOk();
        $this->assertSame(0, $order->items()->count());
    }

    public function test_autosave_rejects_a_tampered_token(): void
    {
        $this->actingAs(TRUser::create([]))
            ->postJson('/_kinetix/tables/table-repeater', [
                'token'  => 'not-a-valid-token',
                'values' => ['name' => 'x'],
            ])
            ->assertStatus(400);
    }
}
