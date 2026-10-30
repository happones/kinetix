<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\AddressPicker;
use Happones\Kinetix\Locations\KinetixLocations;
use Happones\Kinetix\Locations\LocationProvider;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** A source outside the database — an API, a file. */
class ArrayLocationProvider implements LocationProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(public array $config = []) {}

    public function countries(): array
    {
        return ['AR' => 'Argentina'];
    }

    public function states(string $country, string $search = '', int $limit = 1000): array
    {
        return $country === 'AR' ? [['value' => 'C', 'label' => 'Córdoba']] : [];
    }

    public function cities(string $country, ?string $state, string $search = '', int $limit = 50): array
    {
        return $state === 'C' ? [['value' => '10', 'label' => 'Villa María']] : [];
    }

    public function stateLabel(string $country, string $state): ?string
    {
        return $country === 'AR' && $state === 'C' ? 'Córdoba' : null;
    }

    public function cityLabel(string $country, ?string $state, string $city): ?string
    {
        return $state === 'C' && $city === '10' ? 'Villa María' : null;
    }
}

/**
 * AddressPicker in locations mode: country → state → city from a provider.
 */
class AddressLocationsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.locations.providers.array', ['driver' => ArrayLocationProvider::class, 'region' => 'south']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The nnjeim/world shape: `status` on countries only.
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('iso2', 2);
            $table->string('name');
            $table->tinyInteger('status')->default(1);
        });

        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->index();
            $table->string('name');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->index();
            $table->foreignId('state_id')->index();
            $table->string('name');
        });

        $mx = DB::table('countries')->insertGetId(['iso2' => 'MX', 'name' => 'Mexico']);
        $us = DB::table('countries')->insertGetId(['iso2' => 'US', 'name' => 'United States']);
        DB::table('countries')->insert(['iso2' => 'MC', 'name' => 'Monaco']);
        DB::table('countries')->insert(['iso2' => 'XX', 'name' => 'Disabled', 'status' => 0]);

        DB::table('states')->insert([
            ['id' => 1, 'country_id' => $mx, 'name' => 'Jalisco'],
            ['id' => 2, 'country_id' => $mx, 'name' => 'Aguascalientes'],
            ['id' => 3, 'country_id' => $us, 'name' => 'Texas'],
        ]);

        DB::table('cities')->insert([
            ['id' => 10, 'country_id' => $mx, 'state_id' => 1, 'name' => 'Guadalajara'],
            ['id' => 11, 'country_id' => $mx, 'state_id' => 1, 'name' => 'Zapopan'],
            ['id' => 12, 'country_id' => $mx, 'state_id' => 2, 'name' => 'Calvillo'],
            ['id' => 13, 'country_id' => $us, 'state_id' => 3, 'name' => 'Austin'],
            ['id' => 14, 'country_id' => $mx, 'state_id' => 1, 'name' => '100% Tequila'],
        ]);
    }

    private function provider(string $name = 'world'): LocationProvider
    {
        return app(KinetixLocations::class)->provider($name);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function errors(AddressPicker $picker, array $value): array
    {
        return Validator::make(['address' => $value], ['address' => $picker->getRules()])->errors()->all();
    }

    public function test_the_world_tables_list_active_countries_and_each_level_by_its_parent(): void
    {
        $provider = $this->provider();

        $this->assertSame(['MX' => 'Mexico', 'MC' => 'Monaco', 'US' => 'United States'], $provider->countries());
        $this->assertSame(
            [['value' => '2', 'label' => 'Aguascalientes'], ['value' => '1', 'label' => 'Jalisco']],
            $provider->states('mx'),
        );
        $this->assertSame(['Zapopan'], array_column($provider->cities('MX', '1', 'za'), 'label'));
        $this->assertSame([], $provider->cities('US', '1'), 'A state of another country lists nothing');
        $this->assertNull($provider->stateLabel('US', '1'));
        $this->assertNull($provider->cityLabel('MX', '2', '10'));
        $this->assertSame('Guadalajara', $provider->cityLabel('MX', '1', '10'));
    }

    public function test_a_search_escapes_like_wildcards(): void
    {
        $this->assertSame(['100% Tequila'], array_column($this->provider()->cities('MX', '1', '0%'), 'label'));
    }

    public function test_the_altwaireb_tables_leave_inactive_and_deleted_rows_out(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::table('states', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::table('cities', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        DB::table('states')->where('id', 2)->update(['is_active' => false]);
        DB::table('cities')->where('id', 11)->update(['deleted_at' => now()]);

        $provider = $this->provider('countries');

        $this->assertSame(['Jalisco'], array_column($provider->states('MX'), 'label'));
        $this->assertSame(['100% Tequila', 'Guadalajara'], array_column($provider->cities('MX', '1'), 'label'));
    }

    public function test_lists_and_labels_are_cached_but_searches_are_not(): void
    {
        $this->provider()->states('MX');
        $this->provider()->stateLabel('MX', '1');

        $fresh = new KinetixLocations;

        DB::enableQueryLog();
        $fresh->provider('world')->states('MX');
        $fresh->provider('world')->stateLabel('MX', '1');
        $this->assertCount(0, DB::getQueryLog(), 'Served from the cache store');

        $fresh->provider('world')->states('MX', 'Jal');
        $this->assertNotEmpty(DB::getQueryLog());
    }

    public function test_a_custom_provider_class_receives_its_config(): void
    {
        $provider = $this->provider('array')->inner();

        $this->assertInstanceOf(ArrayLocationProvider::class, $provider);
        $this->assertSame('south', $provider->config['region']);
        $this->assertSame(['AR' => 'Argentina'], AddressPicker::make('address')->locations('array')->getCountries());
    }

    public function test_free_text_stays_the_default_and_locations_ships_a_token(): void
    {
        $free = AddressPicker::make('address')->toData('create');

        $this->assertNull($free->addressLocations);
        $this->assertArrayHasKey('DE', $free->options);

        $located = AddressPicker::make('address')->locations()->countries(['MX' => 'México'])->toData('create');

        $this->assertSame(['MX' => 'Mexico'], $located->options, 'countries() narrows the provider list');
        $this->assertSame('world', SignedDescriptor::open((string) $located->addressLocations)['locations']);
    }

    public function test_the_endpoint_lists_states_whole_and_pages_cities(): void
    {
        config(['kinetix.locations.cities_limit' => 2]);

        $token = (string) AddressPicker::make('address')->locations()->toData('create')->addressLocations;

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'states', 'country' => 'MX']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertJson(['complete' => true, 'options' => [['value' => '2', 'label' => 'Aguascalientes'], ['value' => '1', 'label' => 'Jalisco']]]);

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'cities', 'country' => 'MX', 'state' => '1']))
            ->assertOk()
            ->assertJsonPath('complete', false)
            ->assertJsonCount(2, 'options');

        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'cities', 'country' => 'MX', 'state' => '1', 'q' => 'zap']))
            ->assertJsonPath('options.0.label', 'Zapopan');
    }

    public function test_the_endpoint_only_answers_a_token_a_form_minted(): void
    {
        $this->getJson(route('kinetix.forms.locations', ['token' => 'forged', 'level' => 'states', 'country' => 'MX']))->assertStatus(400);

        $unknown = SignedDescriptor::seal(['locations' => 'nope']);
        $this->getJson(route('kinetix.forms.locations', ['token' => $unknown, 'level' => 'states', 'country' => 'MX']))->assertStatus(400);

        $token = SignedDescriptor::seal(['locations' => 'world']);
        $this->getJson(route('kinetix.forms.locations', ['token' => $token, 'level' => 'users', 'country' => 'MX']))->assertStatus(422);
    }

    public function test_a_chosen_state_and_city_must_belong_to_their_parents(): void
    {
        $picker = AddressPicker::make('address')->locations();

        $this->assertSame([], $this->errors($picker, ['country' => 'MX', 'stateId' => '1', 'cityId' => '10']));
        $this->assertSame([__('kinetix.address_state_invalid')], $this->errors($picker, ['country' => 'US', 'stateId' => '1']));
        $this->assertSame([__('kinetix.address_city_invalid')], $this->errors($picker, ['country' => 'MX', 'stateId' => '2', 'cityId' => '10']));
        $this->assertSame([__('kinetix.address_country_invalid')], $this->errors($picker, ['country' => 'XX']));
        $this->assertSame([__('kinetix.address_country_first')], $this->errors($picker, ['stateId' => '1']));
    }

    public function test_typed_parts_are_only_accepted_where_there_is_nothing_to_choose(): void
    {
        $picker = AddressPicker::make('address')->locations();

        $this->assertSame([__('kinetix.address_state_pick')], $this->errors($picker, ['country' => 'MX', 'state' => 'Jalisco']));
        $this->assertSame([__('kinetix.address_city_pick')], $this->errors($picker, ['country' => 'MX', 'stateId' => '1', 'city' => 'Tlaquepaque']));

        // Monaco has no states in the catalog: the address can still be completed.
        $this->assertSame([], $this->errors($picker, ['country' => 'MC', 'state' => 'Monaco', 'city' => 'Monte Carlo']));
    }

    public function test_required_parts_and_free_text_validation(): void
    {
        $picker = AddressPicker::make('address')->requiredFields(['line1', 'city']);

        $this->assertSame([], $this->errors($picker, ['line1' => 'Av. Juárez 1', 'city' => 'Guadalajara', 'country' => 'MX']));
        $this->assertSame(
            [__('kinetix.address_part_required', ['part' => __('kinetix.address_city')])],
            $this->errors($picker, ['line1' => 'Av. Juárez 1']),
        );
        $this->assertSame([__('kinetix.address_country_invalid')], $this->errors($picker, ['line1' => 'x', 'city' => 'y', 'country' => 'ZZ']));
        $this->assertSame([], Validator::make(['address' => null], ['address' => $picker->getRules()])->errors()->all(), 'An optional empty address passes');
    }

    public function test_saving_stores_the_providers_names_and_only_the_address_parts(): void
    {
        $located = AddressPicker::make('address')->locations()->dehydrate([
            'country' => 'mx', 'stateId' => '1', 'state' => 'Forged', 'cityId' => '10', 'city' => 'Forged', 'line1' => ' Av. Juárez 1 ', 'admin' => true,
        ]);

        $this->assertSame([
            'line1'      => 'Av. Juárez 1', 'line2' => null, 'city' => 'Guadalajara', 'state' => 'Jalisco',
            'postalCode' => null, 'country' => 'MX', 'stateId' => '1', 'cityId' => '10',
        ], $located);

        $free = AddressPicker::make('address')->dehydrate(['city' => 'Guadalajara', 'stateId' => '1']);

        $this->assertArrayNotHasKey('stateId', $free);
        $this->assertSame('Guadalajara', $free['city']);
    }
}
