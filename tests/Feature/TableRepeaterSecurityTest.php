<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TableRepeater;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class RepeaterInvoice extends Model
{
    protected $table = 'repeater_invoices';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<RepeaterLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RepeaterLine::class, 'invoice_id');
    }
}

class RepeaterLine extends Model
{
    protected $table = 'repeater_lines';

    public $timestamps = false;

    protected $guarded = [];
}

class RepeaterUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class RepeaterInvoicePolicy
{
    public function update(RepeaterUser $user, RepeaterInvoice $invoice): bool
    {
        return $invoice->owner_id === $user->getKey();
    }
}

/**
 * The autosave descriptor is the whole authority behind the row-write endpoints,
 * so it must only exist for a render that can write, and must not be usable by
 * anyone other than the user it was minted for.
 */
class TableRepeaterSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('repeater_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('owner_id')->nullable();
        });

        Schema::create('repeater_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->string('description')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });
    }

    private function field(): TableRepeater
    {
        return TableRepeater::make('lines')
            ->relationship('lines')
            ->autosave()
            ->schema([TextInput::make('description')]);
    }

    public function test_a_view_render_is_not_given_a_write_token(): void
    {
        $invoice = RepeaterInvoice::create([]);

        $data = $this->field()->toData('view', $invoice);

        $this->assertNull($data?->autosaveToken);
    }

    public function test_a_disabled_field_is_not_given_a_write_token(): void
    {
        $invoice = RepeaterInvoice::create([]);

        $data = $this->field()->disabled()->toData('edit', $invoice);

        $this->assertNull($data?->autosaveToken);
    }

    public function test_an_editable_render_still_gets_a_token(): void
    {
        $invoice = RepeaterInvoice::create([]);

        $data = $this->field()->toData('edit', $invoice);

        $this->assertNotNull($data?->autosaveToken);
    }

    public function test_the_parents_policy_governs_a_row_write(): void
    {
        Gate::policy(RepeaterInvoice::class, RepeaterInvoicePolicy::class);

        $owner   = RepeaterUser::create([]);
        $other   = RepeaterUser::create([]);
        $invoice = RepeaterInvoice::create(['owner_id' => $owner->getKey()]);

        $this->actingAs($owner);
        $token = $this->field()->toData('edit', $invoice)?->autosaveToken;

        // The owner may add a row.
        $this->actingAs($owner)
            ->postJson(route('kinetix.table-repeater.store'), [
                'token'  => $token,
                'values' => ['description' => 'Consulting'],
            ])
            ->assertOk();

        // Someone else replaying the owner's token may not — the binding check
        // fires before the policy even gets a say.
        $this->actingAs($other)
            ->postJson(route('kinetix.table-repeater.store'), [
                'token'  => $token,
                'values' => ['description' => 'Injected'],
            ])
            ->assertForbidden();

        $this->assertSame(['Consulting'], $invoice->lines()->pluck('description')->all());
    }

    public function test_an_expired_descriptor_is_rejected(): void
    {
        config()->set('kinetix.tables.token_ttl', 1);

        $invoice = RepeaterInvoice::create([]);
        $token   = $this->field()->toData('edit', $invoice)?->autosaveToken;

        $this->travel(2)->minutes();

        $this->postJson(route('kinetix.table-repeater.store'), [
            'token'  => $token,
            'values' => ['description' => 'Too late'],
        ])->assertForbidden();

        $this->assertSame(0, $invoice->lines()->count());
    }
}
