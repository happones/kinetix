<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class InfolistAuthUser extends Authenticatable {}

class InfolistAuthInvoice extends Model
{
    protected $table = 'infolist_auth_invoices';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['amount' => 'integer'];
}

class InfolistEntryAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('infolist_auth_invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('amount');
        });

        Gate::define('viewFinancials', fn ($user, ?InfolistAuthInvoice $invoice = null) => $invoice?->amount < 1000);

        $this->actingAs(new InfolistAuthUser);
    }

    public function test_entry_is_visible_when_authorized(): void
    {
        $invoice = InfolistAuthInvoice::create(['amount' => 500]);

        $data = TextEntry::make('amount')->authorize('viewFinancials')->toData('view', $invoice);

        $this->assertNotNull($data);
    }

    public function test_entry_is_hidden_when_unauthorized(): void
    {
        $invoice = InfolistAuthInvoice::create(['amount' => 5000]);

        $data = TextEntry::make('amount')->authorize('viewFinancials')->toData('view', $invoice);

        $this->assertNull($data);
    }

    public function test_authorize_without_a_record_defers_to_visible(): void
    {
        $data = TextEntry::make('amount')->authorize('viewFinancials')->toData('view', null);

        $this->assertNotNull($data);
    }

    public function test_authorize_accepts_an_explicit_subject(): void
    {
        $invoice = InfolistAuthInvoice::create(['amount' => 5000]);

        $data = TextEntry::make('amount')->authorize('viewFinancials', $invoice)->toData('view', null);

        $this->assertNull($data);
    }

    public function test_authorize_accepts_a_closure(): void
    {
        $this->assertNull(TextEntry::make('x')->authorize(fn () => false)->toData('view', null));
        $this->assertNotNull(TextEntry::make('x')->authorize(fn () => true)->toData('view', null));
    }

    public function test_authorize_accepts_a_bare_boolean(): void
    {
        $this->assertNull(TextEntry::make('x')->authorize(false)->toData('view', null));
        $this->assertNotNull(TextEntry::make('x')->authorize(true)->toData('view', null));
    }
}
