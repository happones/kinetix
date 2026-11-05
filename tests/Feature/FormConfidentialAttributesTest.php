<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;
use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FcaCustomer extends Model
{
    use HasConfidentialAttributes;

    protected $table = 'fca_customers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ssn'  => ConfidentialCast::class,
            'card' => ConfidentialCast::class.':4,head',
        ];
    }
}

class FcaOrder extends Model
{
    protected $table = 'fca_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(FcaCustomer::class, 'owner_id');
    }
}

/**
 * A confidential attribute never travels to an edit form, masked or not: the
 * mask is not the value, and saving it back used to overwrite the real one.
 */
class FormConfidentialAttributesTest extends TestCase
{
    private const SSN = '123-45-6789';

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('fca_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('ssn')->nullable();
            $table->text('card')->nullable();
        });

        Schema::create('fca_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
        });

        Schema::create('kinetix_confidential_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id')->unique();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->boolean('is_current')->default(false);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        ConfidentialKey::create([
            'key_id'      => 'test-key',
            'driver'      => 'local',
            'wrapped_key' => (new LocalKeyManager)->generateDataKey()['wrapped'],
            'is_current'  => true,
        ]);
    }

    private function customer(): FcaCustomer
    {
        return FcaCustomer::create(['name' => 'Ada', 'ssn' => self::SSN, 'card' => '4242424242424242']);
    }

    private function plaintext(FcaCustomer $customer, string $attribute = 'ssn'): ?string
    {
        return Confidential::revealed(fn (): ?string => FcaCustomer::query()->findOrFail($customer->getKey())->{$attribute});
    }

    private function form(FcaCustomer $customer): Form
    {
        return Form::make($customer)->schema([
            TextInput::make('name'),
            TextInput::make('ssn'),
            TextInput::make('card'),
        ])->fill($customer);
    }

    public function test_the_edit_form_carries_neither_the_value_nor_its_mask(): void
    {
        $customer = $this->customer();

        $payload = (string) json_encode($this->form($customer)->toArray());

        $this->assertStringNotContainsString('6789', $payload);
        $this->assertStringNotContainsString('•', $payload);
        $this->assertStringNotContainsString('4242', $payload);

        // Unlocked too: an edit form is not where a reveal happens.
        $unlocked = (string) Confidential::revealed(fn () => json_encode($this->form($customer)->toArray()));
        $this->assertStringNotContainsString(self::SSN, $unlocked);
    }

    public function test_saving_the_form_untouched_keeps_the_stored_value(): void
    {
        $customer = $this->customer();
        $form     = $this->form($customer);
        $rendered = $form->toArray()['data'];

        $state = $form->getState(['name' => 'Ada L.', 'ssn' => $rendered['ssn'] ?? null, 'card' => '']);

        $this->assertSame(['name' => 'Ada L.'], $state);

        $customer->fill($state)->save();

        $this->assertSame(self::SSN, $this->plaintext($customer));
        $this->assertSame('4242424242424242', $this->plaintext($customer, 'card'));
    }

    public function test_a_new_value_is_saved(): void
    {
        $customer = $this->customer();
        $state    = $this->form($customer)->getState(['name' => 'Ada', 'ssn' => '987-65-4321', 'card' => '']);

        $this->assertSame('987-65-4321', $state['ssn']);

        $customer->fill($state)->save();

        $this->assertSame('987-65-4321', $this->plaintext($customer));
    }

    public function test_assigning_the_masked_value_back_keeps_the_stored_ciphertext(): void
    {
        $customer = $this->customer();
        $stored   = DB::table('fca_customers')->where('id', $customer->id)->value('ssn');

        $customer       = FcaCustomer::query()->findOrFail($customer->id);
        $customer->ssn  = $customer->ssn;
        $customer->card = $customer->card;
        $customer->save();

        $this->assertSame($stored, DB::table('fca_customers')->where('id', $customer->id)->value('ssn'));
        $this->assertSame(self::SSN, $this->plaintext($customer));
        $this->assertSame('4242424242424242', $this->plaintext($customer, 'card'));
    }

    public function test_a_related_models_confidential_attribute_is_withheld(): void
    {
        $customer = $this->customer();
        $order    = FcaOrder::create(['owner_id' => $customer->id]);

        $form = Form::make($order)->schema([
            TextInput::make('owner.name'),
            TextInput::make('owner.ssn'),
        ])->fill($order);

        $payload = (string) json_encode($form->toArray());

        $this->assertStringContainsString('Ada', $payload);
        $this->assertStringNotContainsString('6789', $payload);
        $this->assertArrayNotHasKey('owner.ssn', $form->getState(['owner.name' => 'Ada', 'owner.ssn' => '']));
    }

    public function test_infolists_and_tables_still_show_the_mask(): void
    {
        $customer = $this->customer();
        $mask     = Confidential::mask(self::SSN);

        $infolist = (string) json_encode(Infolist::make($customer)->schema([TextEntry::make('ssn')])->toArray());
        $this->assertStringContainsString(trim((string) json_encode($mask), '"'), $infolist);

        $table = Table::make(FcaCustomer::query())->columns([TextColumn::make('ssn')])->toArray();
        $this->assertSame($mask, $table['records'][0]['values']['ssn'] ?? null);
    }
}
