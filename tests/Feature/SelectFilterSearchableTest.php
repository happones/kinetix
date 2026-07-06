<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Filters\MultiSelectFilter;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class UserForFilter extends Model
{
    protected $table = 'users_for_filter_test';

    public $timestamps = false;

    protected $guarded = [];
}

class SelectFilterSearchableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users_for_filter_test', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
        });

        UserForFilter::create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        UserForFilter::create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);
    }

    public function test_select_filter_searchable_flag(): void
    {
        $filter = SelectFilter::make('user_id')->searchable();
        $data   = $filter->toData();

        $this->assertTrue($data->isSearchable);
        $this->assertNull($data->searchToken);
    }

    public function test_select_filter_remote_search_using(): void
    {
        $filter = SelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name', 'email']);

        $data = $filter->toData();

        $this->assertTrue($data->isSearchable);
        $this->assertNotNull($data->searchToken);

        $decrypted = Crypt::decrypt($data->searchToken);
        $this->assertSame(UserForFilter::class, $decrypted['model']);
        $this->assertSame('name', $decrypted['label']);
        $this->assertSame(['name', 'email'], $decrypted['columns']);
        $this->assertSame('id', $decrypted['value']);
    }

    public function test_select_filter_resolves_selected_option_label_from_request(): void
    {
        // Mock request input
        request()->merge([
            'filters' => [
                'user_id' => '1',
            ],
        ]);

        $filter = SelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name']);

        $data = $filter->toData();

        $this->assertSame([
            '1' => 'Alice Smith',
        ], $data->options);
    }

    public function test_multi_select_filter_resolves_multiple_selected_options_from_request(): void
    {
        // Mock request input
        request()->merge([
            'filters' => [
                'user_ids' => ['1', '2'],
            ],
        ]);

        $filter = MultiSelectFilter::make('user_ids')
            ->searchUsing(UserForFilter::class, 'name', ['name']);

        $data = $filter->toData();

        $this->assertSame([
            '1' => 'Alice Smith',
            '2' => 'Bob Jones',
        ], $data->options);
    }
}
