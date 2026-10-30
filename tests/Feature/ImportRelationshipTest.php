<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class RelRecipient extends AuthUser
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class RelCategory extends Model
{
    protected $table = 'rel_categories';

    protected $guarded = [];

    public $timestamps = false;
}

class RelTag extends Model
{
    protected $table = 'rel_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class RelProduct extends Model
{
    protected $table = 'rel_products';

    protected $guarded = [];

    public $timestamps = false;

    public function category(): BelongsTo
    {
        return $this->belongsTo(RelCategory::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelTag::class, 'rel_product_tag', 'product_id', 'tag_id');
    }
}

class RelProductImporter extends Importer
{
    protected static ?string $model = RelProduct::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku'),
            ImportColumn::make('category')
                ->label('Category')
                ->relationship('category', ['id', 'name'])
                ->relationshipQuery(fn (Builder $query, array $context) => $query->where('team_id', $context['team_id'] ?? 1)),
            ImportColumn::make('tags')->label('Tags')->relationship('tags', 'name')->multiple(','),
        ];
    }
}

class CreatingProductImporter extends RelProductImporter
{
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku'),
            ImportColumn::make('category')
                ->relationship('category', ['id', 'name'])
                ->relationshipQuery(fn (Builder $query, array $context) => $query->where('team_id', $context['team_id']))
                ->createMissing(fn (string $name, array $context) => ['name' => $name, 'team_id' => $context['team_id']]),
        ];
    }

    public function importRow(array $data): void
    {
        if ($data['sku'] === 'BAD') {
            throw new \RuntimeException('Could not save.');
        }

        parent::importRow($data);
    }
}

/** Names that look like ids: names are tried first. */
class NamedFirstImporter extends RelProductImporter
{
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku'),
            ImportColumn::make('category')->relationship('category', ['name', 'id']),
        ];
    }
}

/**
 * Relationship columns: a cell names the related record by id or by any
 * column, resolved in batches.
 */
class ImportRelationshipTest extends TestCase
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

        Schema::create('rel_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->string('name');
        });

        Schema::create('rel_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('rel_products', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('sku');
            $table->unsignedInteger('category_id')->nullable();
        });

        Schema::create('rel_product_tag', function (Blueprint $table): void {
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('tag_id');
        });

        RelCategory::create(['id' => 1, 'team_id' => 1, 'name' => 'Bebidas']);
        RelCategory::create(['id' => 2, 'team_id' => 1, 'name' => 'Botanas']);
        RelCategory::create(['id' => 3, 'team_id' => 2, 'name' => 'Lácteos']);
        RelCategory::create(['id' => 4, 'team_id' => 1, 'name' => '2024']);

        RelTag::create(['id' => 1, 'name' => 'Oferta']);
        RelTag::create(['id' => 2, 'name' => 'Nuevo']);

        Storage::fake('local');
    }

    /**
     * @param class-string<Importer> $importer
     * @param array<string, mixed>   $context
     */
    private function import(string $importer, string $csv, array $context = ['team_id' => 1]): string
    {
        $user = RelRecipient::create(['name' => 'Omar']);
        Storage::disk('local')->put('kinetix-imports/rows.csv', $csv);

        $headers = str_getcsv(strtok($csv, "\n"), ',', '"', '');

        (new ImportProcessor(
            $importer,
            'kinetix-imports/rows.csv',
            ['delimiter' => ',', 'enclosure' => '"', 'skipLines' => 0, 'hasHeader' => true],
            array_flip($headers),
            RelRecipient::class,
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
     * @return array<string, int|null>
     */
    private function categories(): array
    {
        return RelProduct::query()->orderBy('sku')->pluck('category_id', 'sku')
            ->map(fn ($id) => $id === null ? null : (int) $id)->all();
    }

    public function test_a_cell_may_hold_the_id_or_the_name_in_any_case(): void
    {
        $this->import(RelProductImporter::class, "sku,category\nA,1\nB,botanas\nC, Bebidas \nD,\n");

        $this->assertSame(['A' => 1, 'B' => 2, 'C' => 1, 'D' => null], $this->categories());
    }

    public function test_a_cell_that_matches_nothing_or_several_records_fails_its_row(): void
    {
        RelCategory::create(['team_id' => 1, 'name' => 'Botanas']);

        $this->import(RelProductImporter::class, "sku,category\nA,Panadería\nB,Botanas\nC,Bebidas\n");

        $this->assertSame(['C' => 1], $this->categories());

        $csv = $this->failedRowsCsv();
        $this->assertStringContainsString(__('kinetix.import_related_not_found', ['attribute' => 'Category', 'value' => 'Panadería']), $csv);
        $this->assertStringContainsString(__('kinetix.import_related_ambiguous', ['attribute' => 'Category', 'value' => 'Botanas']), $csv);
    }

    public function test_another_tenants_records_never_match(): void
    {
        $this->import(RelProductImporter::class, "sku,category\nA,3\nB,Lácteos\nC,Bebidas\n");

        $this->assertSame(['C' => 1], $this->categories());
    }

    public function test_the_match_order_is_configurable(): void
    {
        $this->import(NamedFirstImporter::class, "sku,category\nA,2024\nB,2\n");

        $this->assertSame(['A' => 4, 'B' => 2], $this->categories());
    }

    public function test_a_chunk_resolves_its_cells_in_batched_queries(): void
    {
        $rows = '';

        for ($i = 0; $i < 300; $i++) {
            $rows .= "P{$i},".['1', 'Botanas', 'BEBIDAS'][$i % 3]."\n";
        }

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'rel_categories')) {
                $lookups++;
            }
        });

        $this->import(RelProductImporter::class, "sku,category\n".$rows);

        $this->assertSame(300, RelProduct::query()->whereNotNull('category_id')->count());
        $this->assertLessThanOrEqual(2, $lookups, 'One query per match column, not one per row');
    }

    public function test_a_multiple_column_syncs_a_belongs_to_many(): void
    {
        $this->import(RelProductImporter::class, "sku,tags\nA,\"oferta, Nuevo\"\nB,\nC,\"Oferta, Agotado\"\n");

        $a = RelProduct::query()->where('sku', 'A')->firstOrFail();
        $this->assertSame([1, 2], $a->tags()->orderBy('rel_tags.id')->pluck('rel_tags.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, RelProduct::query()->where('sku', 'B')->firstOrFail()->tags()->count());
        $this->assertFalse(RelProduct::query()->where('sku', 'C')->exists());
        $this->assertStringContainsString(__('kinetix.import_related_not_found', ['attribute' => 'Tags', 'value' => 'Agotado']), $this->failedRowsCsv());
    }

    public function test_missing_records_are_not_created_unless_asked(): void
    {
        $this->import(RelProductImporter::class, "sku,category\nA,Panadería\n");

        $this->assertSame(4, RelCategory::query()->count());
    }

    public function test_create_missing_creates_once_and_survives_a_failed_row(): void
    {
        $this->import(CreatingProductImporter::class, "sku,category\nBAD,Panadería\nA,Panadería\nB,panadería\nC,Bebidas\n", ['team_id' => 1]);

        $created = RelCategory::query()->where('name', 'Panadería')->get();

        $this->assertCount(1, $created, 'The failed row\'s category rolled back; the next row created it again, once');
        $this->assertSame(1, (int) $created[0]->team_id);
        $this->assertSame(['A' => $created[0]->id, 'B' => $created[0]->id, 'C' => 1], $this->categories());
    }

    public function test_a_custom_import_row_reads_the_resolved_key(): void
    {
        $importer = new class extends RelProductImporter
        {
            public static array $seen = [];

            public function importRow(array $data): void
            {
                self::$seen[] = $data['category'];
            }
        };

        $this->import($importer::class, "sku,category\nA,Botanas\n");

        $this->assertSame([2], $importer::$seen);
    }

    public function test_a_column_on_a_relation_kinetix_cannot_fill_fails_its_rows(): void
    {
        $importer = new class extends RelProductImporter
        {
            public static function getColumns(): array
            {
                return [ImportColumn::make('sku'), ImportColumn::make('category')->relationship('missingRelation')];
            }
        };

        $this->import($importer::class, "sku,category\nA,1\n");

        $this->assertSame(0, RelProduct::query()->count());
        $this->assertStringContainsString('BelongsTo or BelongsToMany', $this->failedRowsCsv());
    }
}
