<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;
use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

class ConfidentialCastTestCustomer extends Model
{
    use HasConfidentialAttributes;

    protected $table = 'confidential_cast_test_customers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'national_id' => ConfidentialCast::class,
            'card_number' => ConfidentialCast::class.':4,head',
        ];
    }
}

class ConfidentialCastTest extends TestCase
{
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

        Schema::create('confidential_cast_test_customers', function (Blueprint $table) {
            $table->id();
            $table->text('national_id')->nullable();
            $table->text('card_number')->nullable();
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

        $generated = (new LocalKeyManager)->generateDataKey();

        ConfidentialKey::create([
            'key_id'      => 'test-key',
            'driver'      => 'local',
            'wrapped_key' => $generated['wrapped'],
            'is_current'  => true,
        ]);
    }

    public function test_setting_the_attribute_stores_an_encrypted_envelope(): void
    {
        $customer = ConfidentialCastTestCustomer::create(['national_id' => '123-45-6789']);

        $raw = $customer->getRawOriginal('national_id');

        $this->assertNotSame('123-45-6789', $raw);
        $this->assertNotNull(json_decode((string) $raw, true));
    }

    public function test_getting_the_attribute_masks_by_default(): void
    {
        $customer = ConfidentialCastTestCustomer::create(['national_id' => '123-45-6789']);
        $customer->refresh();

        $this->assertSame(str_repeat('•', 7).'6789', $customer->national_id);
    }

    public function test_getting_the_attribute_reveals_the_real_value_once_unlocked(): void
    {
        $customer = ConfidentialCastTestCustomer::create(['national_id' => '123-45-6789']);
        $customer->refresh();

        Confidential::revealed(function () use ($customer): void {
            $this->assertSame('123-45-6789', $customer->national_id);
        });
    }

    public function test_per_field_colon_arguments_control_visible_count_and_position(): void
    {
        $customer = ConfidentialCastTestCustomer::create(['card_number' => '4242424242424242']);
        $customer->refresh();

        $this->assertSame('4242'.str_repeat('•', 12), $customer->card_number);
    }

    public function test_null_passes_through_unchanged(): void
    {
        $customer = ConfidentialCastTestCustomer::create(['national_id' => null]);
        $customer->refresh();

        $this->assertNull($customer->national_id);
        $this->assertNull($customer->getRawOriginal('national_id'));
    }

    public function test_confidential_columns_helper_reports_the_cast_columns(): void
    {
        $this->assertSame(
            ['national_id', 'card_number'],
            ConfidentialCastTestCustomer::confidentialColumns(),
        );
    }

    public function test_a_short_value_is_masked_entirely(): void
    {
        $this->assertSame('••••', Confidential::mask('1234'));
        $this->assertSame('•', Confidential::mask('7'));
        $this->assertSame('', Confidential::mask(''));
    }

    public function test_at_most_half_of_a_value_is_ever_shown(): void
    {
        $this->assertSame('•••45', Confidential::mask('12345'));
        $this->assertSame('12•••', Confidential::mask('12345', 4, 'head'));
        $this->assertSame('••••5678', Confidential::mask('12345678'));
    }

    public function test_masking_counts_characters_not_bytes(): void
    {
        $this->assertSame('••••úñ', Confidential::mask('ñandúñ', 2));
        $this->assertSame('••••€€', Confidential::mask('€€€€€€', 2));
    }

    public function test_writing_back_the_mask_of_a_short_value_keeps_its_ciphertext(): void
    {
        $customer = ConfidentialCastTestCustomer::create(['national_id' => '1234']);
        $customer->refresh();

        $stored = $customer->getRawOriginal('national_id');

        $this->assertSame('••••', $customer->national_id);

        $customer->national_id = $customer->national_id;
        $customer->save();

        $this->assertSame($stored, $customer->fresh()?->getRawOriginal('national_id'));

        Confidential::revealed(function () use ($customer): void {
            $this->assertSame('1234', $customer->fresh()?->national_id);
        });
    }
}
