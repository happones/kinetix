<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Filters\AddressFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Place extends Model
{
    protected $table = 'places';

    public $timestamps = false;

    protected $guarded = [];
}

class AddressFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('places', function (Blueprint $table) {
            $table->increments('id');
            $table->string('city');
            $table->string('state');
            $table->string('country');
        });

        Place::create(['city' => 'Guadalajara', 'state' => 'Jalisco', 'country' => 'Mexico']);
        Place::create(['city' => 'Austin', 'state' => 'Texas', 'country' => 'United States']);
        Place::create(['city' => 'Lisbon', 'state' => 'Lisboa', 'country' => 'Portugal']);
    }

    public function test_filter_matches_across_columns_with_or_like(): void
    {
        $q = Place::query();
        AddressFilter::make('address')->columns(['city', 'state', 'country'])->apply($q, 'jal');

        $this->assertSame(['Guadalajara'], $q->pluck('city')->all());
    }

    public function test_filter_matches_country_column(): void
    {
        $q = Place::query();
        AddressFilter::make('address')->columns(['city', 'state', 'country'])->apply($q, 'united');

        $this->assertSame(['Austin'], $q->pluck('city')->all());
    }

    public function test_blank_value_is_ignored(): void
    {
        $q = Place::query();
        AddressFilter::make('address')->columns(['city'])->apply($q, '   ');

        $this->assertCount(3, $q->get());
    }

    public function test_like_wildcards_in_the_term_are_escaped(): void
    {
        // An unescaped `%` would match every row and force a full scan, turning
        // the filter into a cheap way to hammer the database.
        $q = Place::query();
        AddressFilter::make('address')->columns(['city'])->apply($q, '%');

        $this->assertCount(0, $q->get());
    }

    public function test_a_literal_underscore_matches_only_itself(): void
    {
        Place::create(['city' => 'a_b', 'state' => 'TX', 'country' => 'United States']);
        Place::create(['city' => 'axb', 'state' => 'TX', 'country' => 'United States']);

        $q = Place::query();
        AddressFilter::make('address')->columns(['city'])->apply($q, 'a_b');

        $this->assertSame(['a_b'], $q->pluck('city')->all());
    }

    public function test_serializes_with_address_type(): void
    {
        $data = AddressFilter::make('address')->columns(['city'])->toData();

        $this->assertSame('address', $data->type);
    }
}
