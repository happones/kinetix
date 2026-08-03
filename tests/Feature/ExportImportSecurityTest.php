<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\DownloadToken;
use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

class GuardedRecord extends Model
{
    protected $table = 'guarded_records';

    public $timestamps = false;

    protected $guarded = [];
}

class GuardedRecordUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Denies everything: proves the endpoints consult the policy at all.
 */
class DenyEverythingPolicy
{
    public function viewAny(GuardedRecordUser $user): bool
    {
        return false;
    }

    public function create(GuardedRecordUser $user): bool
    {
        return false;
    }
}

class GuardedExporter extends Exporter
{
    protected static ?string $model = GuardedRecord::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }
}

class GuardedImporter extends Importer
{
    protected static ?string $model = GuardedRecord::class;

    public static function getColumns(): array
    {
        return [ImportColumn::make('name')];
    }
}

class ExportImportSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('guarded_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });
    }

    public function test_starting_an_export_requires_the_models_view_any_ability(): void
    {
        Gate::policy(GuardedRecord::class, DenyEverythingPolicy::class);

        $response = $this->actingAs(GuardedRecordUser::create([]))
            ->postJson(route('kinetix.exports.start'), [
                'exporter' => GuardedExporter::token(),
                'ids'      => [1, 2, 3],
            ]);

        $response->assertForbidden();
    }

    public function test_an_export_is_queued_when_the_policy_allows_it(): void
    {
        // Faked so the assertion is about the endpoint queueing the work, not
        // about the worker running it — and so the test doesn't depend on the
        // runner's default queue driver having a `jobs` table.
        Queue::fake();

        $response = $this->actingAs(GuardedRecordUser::create([]))
            ->postJson(route('kinetix.exports.start'), [
                'exporter' => GuardedExporter::token(),
            ]);

        $response->assertOk();
        Queue::assertPushed(ExportProcessor::class);
    }

    public function test_nothing_is_queued_when_the_export_is_denied(): void
    {
        Queue::fake();
        Gate::policy(GuardedRecord::class, DenyEverythingPolicy::class);

        $this->actingAs(GuardedRecordUser::create([]))
            ->postJson(route('kinetix.exports.start'), [
                'exporter' => GuardedExporter::token(),
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_importing_requires_the_models_create_ability(): void
    {
        Gate::policy(GuardedRecord::class, DenyEverythingPolicy::class);

        $user = GuardedRecordUser::create([]);

        $this->actingAs($user)
            ->postJson(route('kinetix.imports.upload'), [
                'importer' => GuardedImporter::token(),
                'file'     => UploadedFile::fake()->createWithContent('rows.csv', "name\nAda\n"),
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('kinetix.imports.start'), [
                'importer'  => GuardedImporter::token(),
                'fileToken' => 'irrelevant',
                'mapping'   => ['name' => 0],
            ])
            ->assertForbidden();

        $this->assertSame(0, GuardedRecord::count());
    }

    public function test_export_download_rejects_a_token_minted_for_another_user(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kinetix-exports/report.csv', 'name');

        $owner    = GuardedRecordUser::create([]);
        $attacker = GuardedRecordUser::create([]);

        $token = DownloadToken::mint('local', 'kinetix-exports/report.csv', 'report.csv', $owner->getKey());

        $this->actingAs($attacker)
            ->get(route('kinetix.exports.download', ['token' => $token]))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('kinetix.exports.download', ['token' => $token]))
            ->assertOk();
    }

    public function test_export_download_rejects_an_expired_token(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kinetix-exports/report.csv', 'name');

        config()->set('kinetix.exports.download_ttl', 1);

        $user  = GuardedRecordUser::create([]);
        $token = DownloadToken::mint('local', 'kinetix-exports/report.csv', 'report.csv', $user->getKey());

        $this->travel(2)->minutes();

        $this->actingAs($user)
            ->get(route('kinetix.exports.download', ['token' => $token]))
            ->assertForbidden();
    }

    public function test_csv_cells_that_would_be_evaluated_as_formulas_are_neutralized(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $value) {
            $path = (string) tempnam(sys_get_temp_dir(), 'kinetix_test_');

            $writer = new FileWriter($path, 'csv');
            $writer->writeRow([$value]);
            $writer->close();

            $contents = (string) file_get_contents($path);
            @unlink($path);

            // A leading tab forces the spreadsheet to treat the cell as text.
            $this->assertStringContainsString("\t".$value, $contents, "Value {$value} was not neutralized");
        }
    }

    public function test_a_formula_cell_reaches_xlsx_as_text_not_a_formula(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kinetix_test_');

        $writer = new FileWriter($path, 'xlsx');
        $writer->writeRow(['=HYPERLINK("https://evil.example/?x","click")']);
        $writer->close();

        $spreadsheet = IOFactory::load($path);
        $cell        = $spreadsheet->getActiveSheet()->getCell('A1');
        @unlink($path);

        $this->assertNotSame(
            DataType::TYPE_FORMULA,
            $cell->getDataType(),
            'PhpSpreadsheet typed the cell as a real formula',
        );
    }

    public function test_ordinary_values_are_written_verbatim(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kinetix_test_');

        $writer = new FileWriter((string) $path, 'csv');
        $writer->writeRow(['Ada Lovelace', 42, null]);
        $writer->close();

        $contents = (string) file_get_contents((string) $path);
        @unlink((string) $path);

        $this->assertStringContainsString('Ada Lovelace', $contents);
        $this->assertStringNotContainsString("\t", $contents);
    }
}
