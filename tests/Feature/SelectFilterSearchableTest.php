<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\SearchScope;
use Happones\Kinetix\Tables\Filters\MultiSelectFilter;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
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

    public function test_the_scope_and_modifier_are_sealed_into_the_token(): void
    {
        $data = SelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name'], 'id', FilterOnlyAlice::class)
            ->searchScope(['email' => 'alice@example.com'])
            ->toData();

        $decrypted = Crypt::decrypt($data->searchToken);

        $this->assertSame(['email' => 'alice@example.com'], $decrypted['scope']);
        $this->assertSame(FilterOnlyAlice::class, $decrypted['modifier']);
    }

    public function test_the_search_endpoint_applies_the_filters_scope_and_modifier(): void
    {
        $scoped = SelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name', 'email'])
            ->searchScope(['email' => 'bob@example.com'])
            ->toData();

        $this->postJson('/_kinetix/forms/search', ['token' => $scoped->searchToken, 'q' => ''])
            ->assertOk()
            ->assertExactJson(['options' => ['2' => 'Bob Jones']]);

        $modified = SelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name'], 'id', FilterOnlyAlice::class)
            ->toData();

        $this->postJson('/_kinetix/forms/search', ['token' => $modified->searchToken, 'q' => ''])
            ->assertOk()
            ->assertExactJson(['options' => ['1' => 'Alice Smith']]);
    }

    public function test_selected_labels_never_come_from_outside_the_scope(): void
    {
        // The request names a row (2) the filter's scope excludes: it gets no label.
        request()->merge(['filters' => ['user_id' => ['1', '2']]]);

        $filter = MultiSelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name'])
            ->searchScope(['email' => 'alice@example.com']);

        $this->assertSame(['1' => 'Alice Smith'], $filter->toData()->options);

        $modified = MultiSelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name'], 'id', FilterOnlyBob::class);

        $this->assertSame(['2' => 'Bob Jones'], $modified->toData()->options);
    }

    public function test_an_or_where_modifier_cannot_escape_the_scope(): void
    {
        $token = SelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name'], 'id', FilterAnyoneOrBob::class)
            ->searchScope(['email' => 'alice@example.com'])
            ->toData()
            ->searchToken;

        $this->postJson('/_kinetix/forms/search', ['token' => $token, 'q' => ''])
            ->assertOk()
            ->assertExactJson(['options' => ['1' => 'Alice Smith']]);

        request()->merge(['filters' => ['user_id' => ['1', '2']]]);

        $filter = MultiSelectFilter::make('user_id')
            ->searchUsing(UserForFilter::class, 'name', ['name'], 'id', FilterAnyoneOrBob::class)
            ->searchScope(['email' => 'alice@example.com']);

        $this->assertSame(['1' => 'Alice Smith'], $filter->toData()->options);
    }

    public function test_a_modifiers_ordering_still_applies_and_its_or_stays_grouped(): void
    {
        $names = SearchScope::apply(UserForFilter::query(), [], FilterAnyoneOrBobNewestFirst::class)
            ->pluck('name')
            ->all();

        $this->assertSame(['Bob Jones', 'Alice Smith'], $names);

        $sql = SearchScope::apply(UserForFilter::query(), ['email' => 'alice@example.com'], FilterAnyoneOrBobNewestFirst::class)
            ->toBase()
            ->toSql();

        $this->assertStringContainsString('order by', strtolower($sql));
        $this->assertMatchesRegularExpression('/\(.*like.*or.*like.*\)/i', $sql);
    }

    public function test_a_modifier_that_is_not_an_invokable_class_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SelectFilter::make('user_id')->searchUsing(UserForFilter::class, 'name', ['name'], 'id', 'not-a-class');
    }

    public function test_a_class_without_invoke_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SelectFilter::make('user_id')->searchUsing(UserForFilter::class, 'name', ['name'], 'id', \stdClass::class);
    }

    public function test_a_select_relationship_modifier_must_be_invokable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Select::make('author_id')->relationship('author', 'name', 'not-a-class');
    }
}

class FilterOnlyAlice
{
    public function __invoke(Builder $query): void
    {
        $query->where('name', 'like', 'Alice%');
    }
}

class FilterOnlyBob
{
    public function __invoke(Builder $query): void
    {
        $query->where('name', 'like', 'Bob%');
    }
}

class FilterAnyoneOrBob
{
    public function __invoke(Builder $query): void
    {
        $query->where('name', 'like', 'Alice%')->orWhere('name', 'like', 'Bob%');
    }
}

class FilterAnyoneOrBobNewestFirst
{
    public function __invoke(Builder $query): void
    {
        $query->where('name', 'like', 'Alice%')->orWhere('name', 'like', 'Bob%')->orderByDesc('id');
    }
}
