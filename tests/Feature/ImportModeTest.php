<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\ImportMode;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use LogicException;
use ReflectionProperty;

class ModeRecipient extends AuthUser
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class ModeProduct extends Model
{
    protected $table = 'mode_products';

    protected $guarded = [];

    public $timestamps = false;
}

class ModeCustomer extends Model
{
    use SoftDeletes;

    protected $table = 'mode_customers';

    protected $guarded = [];

    public $timestamps = false;
}

class ModeProductImporter extends Importer
{
    protected static ?string $model = ModeProduct::class;

    protected array $importModes = [ImportMode::Create, ImportMode::Upsert, ImportMode::Update, ImportMode::Replace];

    protected array $matchBy = ['sku'];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku'),
            ImportColumn::make('name'),
            ImportColumn::make('team_id'),
        ];
    }
}

/** Scoped to the team it was started from, as a multi-tenant host must. */
class TeamProductImporter extends ModeProductImporter
{
    protected function query(): Builder
    {
        return ModeProduct::query()->where('team_id', $this->context['team_id']);
    }
}

class ModeCustomerImporter extends Importer
{
    protected static ?string $model = ModeCustomer::class;

    protected array $importModes = [ImportMode::Create, ImportMode::Replace];

    protected ?ImportMode $defaultImportMode = ImportMode::Replace;

    protected array $matchBy = ['email'];

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')->rules(['required', 'email']),
            ImportColumn::make('name'),
        ];
    }

    public function authorizeMode(?Authenticatable $user, ImportMode $mode): bool
    {
        return $mode !== ImportMode::Replace || request()->boolean('may_replace');
    }
}

/** Every row a new customer: Replace still swaps the list. */
class KeylessCustomerImporter extends ModeCustomerImporter
{
    protected array $matchBy = [];
}

class PlainProductImporter extends Importer
{
    protected static ?string $model = ModeProduct::class;

    public static function getColumns(): array
    {
        return [ImportColumn::make('sku'), ImportColumn::make('name')];
    }
}

/**
 * Import modes: what an import does with the records already there.
 */
class ImportModeTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mode_products', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('team_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
        });

        Schema::create('mode_customers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name')->nullable();
            $table->softDeletes();
        });

        Storage::fake('local');
    }

    /**
     * Run an import of $csv in $mode and return the notification body.
     *
     * @param class-string<Importer> $importer
     * @param array<string, mixed>   $context
     */
    private function import(string $importer, string $csv, ?ImportMode $mode, array $context = []): string
    {
        $user = ModeRecipient::create(['name' => 'Omar']);
        Storage::disk('local')->put('kinetix-imports/rows.csv', $csv);

        $headers = str_getcsv(strtok($csv, "\n"), ',', '"', '');

        (new ImportProcessor(
            $importer,
            'kinetix-imports/rows.csv',
            ['delimiter' => ',', 'enclosure' => '"', 'skipLines' => 0, 'hasHeader' => true, 'mode' => $mode?->value],
            array_flip($headers),
            ModeRecipient::class,
            $user->id,
            $context,
        ))->handle();

        return (string) $user->fresh()->notifications()->first()?->data['description'];
    }

    private function failedRowsCsv(): string
    {
        $files = Storage::disk('local')->files('kinetix-exports');

        $this->assertCount(1, $files, 'A failed-rows file should have been published');

        return (string) Storage::disk('local')->get($files[0]);
    }

    /**
     * @return array<string, string>
     */
    private function products(): array
    {
        return ModeProduct::query()->orderBy('sku')->pluck('name', 'sku')->all();
    }

    private function seedProducts(): void
    {
        ModeProduct::create(['sku' => 'A', 'name' => 'Apple']);
        ModeProduct::create(['sku' => 'B', 'name' => 'Banana']);
    }

    public function test_create_adds_rows_and_turns_down_the_ones_that_already_exist(): void
    {
        $this->seedProducts();

        $this->import(ModeProductImporter::class, "sku,name\nA,Apricot\nC,Cherry\n", ImportMode::Create);

        $this->assertSame(['A' => 'Apple', 'B' => 'Banana', 'C' => 'Cherry'], $this->products());
        $this->assertStringContainsString(__('kinetix.import_row_exists'), $this->failedRowsCsv());
    }

    public function test_upsert_updates_what_matches_and_adds_the_rest(): void
    {
        $this->seedProducts();

        $this->import(ModeProductImporter::class, "sku,name\nA,Apricot\nC,Cherry\n", ImportMode::Upsert);

        $this->assertSame(['A' => 'Apricot', 'B' => 'Banana', 'C' => 'Cherry'], $this->products());
    }

    public function test_update_only_touches_existing_records(): void
    {
        $this->seedProducts();

        $this->import(ModeProductImporter::class, "sku,name\nA,Apricot\nC,Cherry\n", ImportMode::Update);

        $this->assertSame(['A' => 'Apricot', 'B' => 'Banana'], $this->products());
        $this->assertStringContainsString(__('kinetix.import_row_not_found'), $this->failedRowsCsv());
    }

    public function test_a_blank_key_matches_nothing_instead_of_any_record_with_a_null_key(): void
    {
        ModeProduct::create(['sku' => null, 'name' => 'Keyless']);

        $this->import(ModeProductImporter::class, "sku,name\n,Fresh\n", ImportMode::Upsert);

        $this->assertSame(2, ModeProduct::query()->count());
    }

    public function test_replace_deactivates_what_the_file_leaves_out_through_the_active_column(): void
    {
        $this->seedProducts();
        ModeProduct::create(['sku' => 'D', 'name' => 'Date', 'active' => false]);

        $body = $this->import(ModeProductImporter::class, "sku,name\nA,Apricot\nC,Cherry\nD,Durian\n", ImportMode::Replace);

        $this->assertSame(['A' => 'Apricot', 'B' => 'Banana', 'C' => 'Cherry', 'D' => 'Durian'], $this->products());
        $this->assertSame(
            ['A' => 1, 'B' => 0, 'C' => 1, 'D' => 1],
            ModeProduct::query()->orderBy('sku')->pluck('active', 'sku')->map(fn ($active): int => (int) $active)->all(),
        );
        $this->assertStringContainsString(__('kinetix.import_deactivated', ['count' => 1]), $body);
    }

    public function test_replace_soft_deletes_the_old_list_and_restores_what_comes_back(): void
    {
        ModeCustomer::create(['email' => 'ada@example.com', 'name' => 'Ada']);
        ModeCustomer::create(['email' => 'bob@example.com', 'name' => 'Bob']);
        ModeCustomer::create(['email' => 'cy@example.com', 'name' => 'Cy'])->delete();

        $this->import(ModeCustomerImporter::class, "email,name\ncy@example.com,Cyrus\nduo@example.com,Duo\n", ImportMode::Replace);

        $this->assertSame(['cy@example.com', 'duo@example.com'], ModeCustomer::query()->orderBy('email')->pluck('email')->all());
        $this->assertSame('Cyrus', ModeCustomer::query()->where('email', 'cy@example.com')->value('name'));
        $this->assertSame(4, ModeCustomer::withTrashed()->count(), 'Deactivated, not erased');
    }

    public function test_replace_without_a_match_key_swaps_the_whole_list(): void
    {
        ModeCustomer::create(['email' => 'ada@example.com']);

        $this->import(KeylessCustomerImporter::class, "email\nada@example.com\nbob@example.com\n", ImportMode::Replace);

        $this->assertSame(2, ModeCustomer::query()->count());
        $this->assertSame(3, ModeCustomer::withTrashed()->count());
    }

    public function test_a_row_that_fails_keeps_its_record_out_of_the_sweep(): void
    {
        ModeCustomer::create(['email' => 'ada@example.com']);
        ModeCustomer::create(['email' => 'bob@example.com']);

        $importer = new class extends ModeCustomerImporter
        {
            public function importRow(array $data): void
            {
                if ($data['name'] === 'broken') {
                    throw new \RuntimeException('Could not save.');
                }

                parent::importRow($data);
            }
        };

        $this->import($importer::class, "email,name\nada@example.com,broken\nnew@example.com,New\n", ImportMode::Replace);

        $this->assertSame(['ada@example.com', 'new@example.com'], ModeCustomer::query()->orderBy('email')->pluck('email')->all());
    }

    public function test_replace_leaves_the_list_alone_when_no_row_imported(): void
    {
        ModeCustomer::create(['email' => 'ada@example.com']);

        $this->import(ModeCustomerImporter::class, "email\nnot-an-email\n", ImportMode::Replace);

        $this->assertSame(1, ModeCustomer::query()->count());
    }

    public function test_replace_only_sweeps_the_records_query_scopes_to(): void
    {
        ModeProduct::create(['sku' => 'A', 'name' => 'Ours', 'team_id' => 1]);
        ModeProduct::create(['sku' => 'A', 'name' => 'Theirs', 'team_id' => 2]);

        $this->import(TeamProductImporter::class, "sku,name,team_id\nB,New,1\n", ImportMode::Replace, ['team_id' => 1]);

        $this->assertSame(
            ['Ours' => 0, 'Theirs' => 1, 'New' => 1],
            ModeProduct::query()->orderBy('id')->pluck('active', 'name')->map(fn ($active): int => (int) $active)->all(),
        );
    }

    public function test_an_importer_without_modes_imports_as_before(): void
    {
        $this->seedProducts();

        $this->import(PlainProductImporter::class, "sku,name\nA,Apricot\n", null);

        $this->assertSame(3, ModeProduct::query()->count());
    }

    public function test_the_dialog_settings_list_the_modes_and_the_default(): void
    {
        $settings = (new ModeCustomerImporter)->settings();

        $this->assertSame('replace', $settings->defaultMode);
        $this->assertSame(['create', 'replace'], array_map(fn ($mode) => $mode->value, $settings->modes));
        $this->assertTrue($settings->modes[1]->destructive);
        $this->assertFalse($settings->modes[0]->destructive);

        $plain = (new PlainProductImporter)->settings();
        $this->assertSame([], $plain->modes);
        $this->assertNull($plain->defaultMode);
    }

    public function test_offering_a_mode_the_importer_cannot_honor_fails_loudly(): void
    {
        $unmatchable = new class extends PlainProductImporter
        {
            protected array $importModes = [ImportMode::Upsert];
        };

        $this->expectException(LogicException::class);
        $unmatchable->getImportModes();
    }

    public function test_replace_needs_soft_deletes_or_an_active_column(): void
    {
        Schema::create('mode_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        $model = new class extends Model
        {
            protected $table = 'mode_tags';
        };

        $importer = new class extends PlainProductImporter
        {
            public static string $modelClass;

            protected array $importModes = [ImportMode::Replace];

            public static function getModel(): string
            {
                return self::$modelClass;
            }
        };
        $importer::$modelClass = $model::class;

        $this->expectException(LogicException::class);
        $importer->getImportModes();
    }

    private function start(string $importerToken, array $extra = []): TestResponse
    {
        return $this->postJson(route('kinetix.imports.start'), [
            'importer'  => $importerToken,
            'fileToken' => SignedDescriptor::seal(['path' => 'kinetix-imports/sample.csv']),
            'mapping'   => ['email' => 0],
            ...$extra,
        ]);
    }

    private function dispatchedMode(): ?string
    {
        $mode = null;

        Queue::assertPushed(ImportProcessor::class, function (ImportProcessor $job) use (&$mode): bool {
            $mode = (new ReflectionProperty($job, 'options'))->getValue($job)['mode'];

            return true;
        });

        return $mode;
    }

    public function test_the_start_endpoint_carries_the_chosen_mode_into_the_job(): void
    {
        Queue::fake();

        $this->start(ModeCustomerImporter::token(), ['mode' => 'create'])->assertOk();

        $this->assertSame('create', $this->dispatchedMode());
    }

    public function test_no_mode_asked_for_starts_in_the_default(): void
    {
        Queue::fake();

        $this->start(ModeCustomerImporter::token(), ['may_replace' => true])->assertOk();

        $this->assertSame('replace', $this->dispatchedMode());
    }

    public function test_a_mode_the_importer_does_not_offer_is_rejected(): void
    {
        Queue::fake();

        $this->start(ModeCustomerImporter::token(), ['mode' => 'upsert'])->assertStatus(422);
        $this->start(PlainProductImporter::token(), ['mode' => 'replace'])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_authorize_mode_can_refuse_a_mode(): void
    {
        Queue::fake();

        $this->start(ModeCustomerImporter::token(), ['mode' => 'replace'])->assertForbidden();

        Queue::assertNothingPushed();
    }
}
