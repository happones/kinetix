<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Query\SearchMode;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SsmCompany extends Model
{
    protected $table = 'ssm_companies';

    public $timestamps = false;

    protected $guarded = [];
}

class SsmOrder extends Model
{
    protected $table = 'ssm_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(SsmCompany::class, 'company_id');
    }
}

/**
 * Select and SelectFilter seal their search mode into the token; the endpoint
 * reads it from there, and a token without one is Contains.
 */
class SelectSearchModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ssm_companies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('ssm_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id')->nullable();
        });

        foreach (['Acme Corp', 'Big Acme', 'Acorn'] as $name) {
            SsmCompany::create(['name' => $name]);
        }
    }

    /** @return list<string> */
    private function search(string $token, string $q): array
    {
        $response = $this->postJson('/_kinetix/forms/search', ['token' => $token, 'q' => $q])->assertOk();

        return array_values($response->json('options'));
    }

    public function test_a_select_without_a_mode_is_contains_and_seals_none(): void
    {
        $data = Select::make('company')->searchUsing(SsmCompany::class)->toData('create');

        $this->assertArrayNotHasKey('mode', SignedDescriptor::open((string) $data->searchToken) ?? []);
        $this->assertEqualsCanonicalizing(['Acme Corp', 'Big Acme'], $this->search((string) $data->searchToken, 'Acme'));
    }

    public function test_a_select_seals_its_mode_and_the_endpoint_applies_it(): void
    {
        $data = Select::make('company')->searchUsing(SsmCompany::class)->searchMode(SearchMode::Prefix)->toData('create');

        $this->assertSame('prefix', SignedDescriptor::open((string) $data->searchToken)['mode']);
        $this->assertSame(['Acme Corp'], $this->search((string) $data->searchToken, 'Acme'));
    }

    public function test_a_select_filter_seals_its_mode_too(): void
    {
        $data = SelectFilter::make('company')->searchUsing(SsmCompany::class)->searchMode(SearchMode::Exact)->toData();

        $this->assertSame('exact', SignedDescriptor::open((string) $data->searchToken)['mode']);
        $this->assertSame(['Acorn'], $this->search((string) $data->searchToken, 'Acorn'));
        $this->assertSame([], $this->search((string) $data->searchToken, 'Acor'));
    }

    public function test_an_unknown_mode_in_a_token_is_contains(): void
    {
        $token = SignedDescriptor::seal([
            'model' => SsmCompany::class, 'label' => 'name', 'columns' => ['name'], 'value' => 'id', 'mode' => 'regex',
        ], DescriptorAudience::Search);

        $this->assertEqualsCanonicalizing(['Acme Corp', 'Big Acme'], $this->search($token, 'Acme'));
    }

    public function test_the_exact_guard_applies_to_a_remote_search_on_the_key(): void
    {
        $token = SignedDescriptor::seal([
            'model' => SsmCompany::class, 'label' => 'name', 'columns' => ['id'], 'value' => 'id', 'mode' => 'exact',
        ], DescriptorAudience::Search);

        $this->assertSame([], $this->search($token, 'letters'));
        $this->assertSame(['Acme Corp'], $this->search($token, '1'));
    }

    public function test_a_relationship_select_seals_its_mode_too(): void
    {
        $data = Select::make('company_id')
            ->forModel(SsmOrder::class)
            ->relationship('company', 'name')
            ->searchable()
            ->searchMode(SearchMode::Prefix)
            ->toData('create');

        $this->assertSame('prefix', SignedDescriptor::open((string) $data->searchToken)['mode']);
        $this->assertSame(['Acme Corp'], $this->search((string) $data->searchToken, 'Acme'));
    }
}
