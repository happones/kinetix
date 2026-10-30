<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\AddressPicker;
use Happones\Kinetix\Locations\CachedPostalCodeProvider;
use Happones\Kinetix\Locations\KinetixLocations;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Addressing by postal code: a provider with a postal-code table fills the
 * state and city from the code and lists the neighborhoods it serves.
 */
class AddressPostalCodeTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.locations.providers.sepomex', [
            'driver'       => 'database',
            'countries'    => ['table' => 'countries', 'key' => 'id', 'code' => 'iso2', 'name' => 'name'],
            'states'       => ['table' => 'states', 'key' => 'id', 'name' => 'name', 'country' => 'country_id'],
            'cities'       => ['table' => 'cities', 'key' => 'id', 'name' => 'name', 'country' => 'country_id', 'state' => 'state_id'],
            'postal_codes' => ['table' => 'postal_codes', 'key' => 'id', 'code' => 'code', 'name' => 'name', 'state' => 'state_id', 'city' => 'city_id'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('iso2', 2);
            $table->string('name');
        });

        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id');
            $table->string('name');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id');
            $table->foreignId('state_id');
            $table->string('name');
        });

        Schema::create('postal_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 5)->index();
            $table->string('name');
            $table->foreignId('state_id');
            $table->foreignId('city_id')->index();
        });

        DB::table('countries')->insert([['id' => 1, 'iso2' => 'MX', 'name' => 'Mexico'], ['id' => 2, 'iso2' => 'US', 'name' => 'United States']]);
        DB::table('states')->insert([['id' => 14, 'country_id' => 1, 'name' => 'Jalisco'], ['id' => 44, 'country_id' => 2, 'name' => 'Texas']]);
        DB::table('cities')->insert([
            ['id' => 39, 'country_id' => 1, 'state_id' => 14, 'name' => 'Guadalajara'],
            ['id' => 120, 'country_id' => 1, 'state_id' => 14, 'name' => 'Zapopan'],
            ['id' => 900, 'country_id' => 2, 'state_id' => 44, 'name' => 'Austin'],
        ]);
        DB::table('postal_codes')->insert([
            ['id' => 1, 'code' => '44160', 'name' => 'Americana', 'state_id' => 14, 'city_id' => 39],
            ['id' => 2, 'code' => '44160', 'name' => 'Arcos Vallarta', 'state_id' => 14, 'city_id' => 39],
            ['id' => 3, 'code' => '44150', 'name' => 'Lafayette', 'state_id' => 14, 'city_id' => 39],
            ['id' => 4, 'code' => '45110', 'name' => 'Ciudad Granja', 'state_id' => 14, 'city_id' => 120],
            ['id' => 5, 'code' => '73301', 'name' => 'Downtown', 'state_id' => 44, 'city_id' => 900],
        ]);
    }

    private function picker(): AddressPicker
    {
        return AddressPicker::make('address')->locations('sepomex')->postalCodeFirst();
    }

    /**
     * @param array<string, mixed> $value
     */
    private function errors(array $value): array
    {
        return Validator::make(['address' => $value], ['address' => $this->picker()->getRules()])->errors()->all();
    }

    public function test_a_postal_code_table_makes_the_provider_know_postal_codes(): void
    {
        $locations = app(KinetixLocations::class);

        $this->assertInstanceOf(CachedPostalCodeProvider::class, $locations->postalCodes('sepomex'));
        $this->assertNull($locations->postalCodes('world'));
    }

    public function test_a_code_tells_its_state_city_and_neighborhoods(): void
    {
        $provider = app(KinetixLocations::class)->postalCodes('sepomex');

        $this->assertSame([
            'state'         => ['value' => '14', 'label' => 'Jalisco'],
            'city'          => ['value' => '39', 'label' => 'Guadalajara'],
            'neighborhoods' => [
                ['value' => '1', 'label' => 'Americana', 'postalCode' => '44160'],
                ['value' => '2', 'label' => 'Arcos Vallarta', 'postalCode' => '44160'],
            ],
        ], $provider?->postalCode('MX', '44160'));

        $this->assertNull($provider?->postalCode('MX', '99999'));
        $this->assertNull($provider?->postalCode('MX', '73301'), "Another country's code is no match");
        $this->assertSame(
            ['Americana', 'Arcos Vallarta', 'Lafayette'],
            array_column($provider?->neighborhoods('MX', '14', '39') ?? [], 'label'),
        );
        $this->assertSame([], $provider?->neighborhoods('US', null, '39'));
    }

    public function test_postal_code_first_orders_the_parts_and_flags_the_field(): void
    {
        $data = $this->picker()->toData('create');

        $this->assertSame(AddressPicker::POSTAL_CODE_FIRST_FIELDS, $data?->addressFields);
        $this->assertTrue($data?->addressNeighborhoods);
        $this->assertTrue($data?->addressPostalCodeLookup);

        $chosen = AddressPicker::make('address')->fields(['postalCode', 'city', 'country'])->locations('sepomex')->postalCodeFirst()->toData('create');

        $this->assertSame(['postalCode', 'city', 'country'], $chosen?->addressFields, 'Parts chosen with fields() are kept');
        $this->assertFalse($chosen?->addressNeighborhoods);
    }

    public function test_postal_code_first_needs_a_provider_that_knows_postal_codes(): void
    {
        $this->expectException(LogicException::class);

        AddressPicker::make('address')->locations('world')->postalCodeFirst()->toData('create');
    }

    public function test_the_endpoint_looks_codes_up_and_lists_a_citys_neighborhoods(): void
    {
        $token = (string) $this->picker()->toData('create')?->addressLocations;

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'postal-code', 'country' => 'MX', 'code' => ' 44160 ']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertJsonPath('match.city', ['value' => '39', 'label' => 'Guadalajara'])
            ->assertJsonCount(2, 'match.neighborhoods');

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'postal-code', 'country' => 'MX', 'code' => '00000']))
            ->assertOk()
            ->assertJsonPath('match', null);

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'neighborhoods', 'country' => 'MX', 'state' => '14', 'city' => '39', 'q' => 'laf']))
            ->assertOk()
            ->assertJsonPath('options', [['value' => '3', 'label' => 'Lafayette', 'postalCode' => '44150']]);

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'neighborhoods', 'country' => 'MX']))
            ->assertStatus(422);

        $world = SignedDescriptor::seal(['locations' => 'world']);
        $this->getJson(route('kinetix.forms.locations', ['token' => $world, 'level' => 'postal-code', 'country' => 'MX', 'code' => '44160']))
            ->assertStatus(422);
    }

    public function test_a_chosen_neighborhood_must_be_in_the_city(): void
    {
        $address = ['country' => 'MX', 'stateId' => '14', 'cityId' => '39'];

        $this->assertSame([], $this->errors([...$address, 'neighborhoodId' => '3']));
        $this->assertSame([__('kinetix.address_neighborhood_invalid')], $this->errors([...$address, 'neighborhoodId' => '4']));
        $this->assertSame([__('kinetix.address_neighborhood_invalid')], $this->errors(['country' => 'MX', 'stateId' => '14', 'neighborhoodId' => '3']));
        $this->assertSame([__('kinetix.address_neighborhood_pick')], $this->errors([...$address, 'neighborhood' => 'Colonia inventada']));
    }

    public function test_saving_takes_the_neighborhoods_name_and_postal_code_from_the_provider(): void
    {
        $saved = $this->picker()->dehydrate([
            'country'        => 'MX', 'postalCode' => '00000', 'stateId' => '14', 'cityId' => '39',
            'neighborhoodId' => '3', 'neighborhood' => 'Forged', 'line1' => 'Av. Chapultepec 15',
        ]);

        $this->assertSame([
            'line1'          => 'Av. Chapultepec 15', 'line2' => null, 'city' => 'Guadalajara', 'state' => 'Jalisco',
            'postalCode'     => '44150', 'country' => 'MX', 'neighborhood' => 'Lafayette', 'stateId' => '14', 'cityId' => '39',
            'neighborhoodId' => '3',
        ], $saved);

        $unknown = $this->picker()->dehydrate(['country' => 'MX', 'stateId' => '14', 'cityId' => '39', 'neighborhoodId' => '4', 'neighborhood' => 'Ciudad Granja']);

        $this->assertNull($unknown['neighborhoodId'], "A neighborhood of another city isn't stored as chosen");
    }

    public function test_pickers_without_a_neighborhood_store_what_they_did_before(): void
    {
        $saved = AddressPicker::make('address')->locations('sepomex')->dehydrate(['country' => 'MX', 'stateId' => '14', 'cityId' => '39']);

        $this->assertArrayNotHasKey('neighborhood', $saved);
        $this->assertArrayNotHasKey('neighborhoodId', $saved);
    }
}
