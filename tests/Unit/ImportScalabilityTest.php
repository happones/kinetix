<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Imports\FileReader;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WideImporter extends Importer
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')->requiredMapping(),
            ImportColumn::make('email'),
        ];
    }
}

class TinyPreviewImporter extends WideImporter
{
    protected ?int $previewRows = 2;

    protected ?int $previewColumns = 3;

    protected ?string $layout = 'fullscreen';

    protected ?int $maxUploadSize = 512;
}

class NoPreviewImporter extends WideImporter
{
    protected ?bool $preview = false;
}

class CountingImporter extends WideImporter
{
    public static int $rows = 0;

    public function chunkSize(): int
    {
        // Deliberately smaller than the file, so the streamed rows have to
        // cross several chunks.
        return 3;
    }

    public function importRow(array $data): void
    {
        static::$rows++;
    }
}

/**
 * The reader and the queued job must both be bounded: a file is allowed to be
 * enormous, so nothing may scale its cost with the row count.
 */
class ImportScalabilityTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    protected array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $this->paths = [];

        parent::tearDown();
    }

    public function test_the_reader_stops_at_the_row_limit_instead_of_parsing_the_file(): void
    {
        $path = $this->csv($this->rowsCsv(500));

        $read = FileReader::read($path, new ImportOptionsData, 10);

        $this->assertSame(['name', 'email'], $read['headers']);
        $this->assertCount(10, $read['rows']);
        $this->assertSame(['Row 1', 'row1@x.com'], $read['rows'][0]);
    }

    public function test_a_zero_row_limit_still_yields_the_headers(): void
    {
        $path = $this->csv($this->rowsCsv(50));

        $read = FileReader::read($path, new ImportOptionsData, 0);

        $this->assertSame(['name', 'email'], $read['headers']);
        $this->assertSame([], $read['rows']);
    }

    public function test_streaming_yields_every_data_row_without_the_header(): void
    {
        $path = $this->csv($this->rowsCsv(300));

        $streamed = 0;
        $first    = null;

        foreach (FileReader::stream($path, new ImportOptionsData) as $row) {
            $streamed++;
            $first ??= $row;
        }

        $this->assertSame(300, $streamed);
        $this->assertSame(['Row 1', 'row1@x.com'], $first);
    }

    public function test_row_counting_excludes_the_header_and_the_skipped_lines(): void
    {
        $path = $this->csv("junk\nname,email\nA,a@x.com\nB,b@x.com\n");

        $this->assertSame(
            2,
            FileReader::countRows($path, new ImportOptionsData(skipLines: 1, hasHeader: true))
        );
    }

    public function test_row_counting_includes_a_final_row_with_no_trailing_newline(): void
    {
        $path = $this->csv("name\nA\nB");

        $this->assertSame(2, FileReader::countRows($path, new ImportOptionsData));
    }

    public function test_a_header_less_file_names_its_columns_after_the_widest_row(): void
    {
        $path = $this->csv("A,B\nC,D,E\n");

        $read = FileReader::read($path, new ImportOptionsData(hasHeader: false), 10);

        $this->assertSame(['Column 1', 'Column 2', 'Column 3'], $read['headers']);
        $this->assertSame([['A', 'B'], ['C', 'D', 'E']], $read['rows']);
    }

    public function test_spreadsheets_are_read_in_windows_and_counted_without_loading(): void
    {
        $path = $this->xlsx(120);

        // A window smaller than the sheet forces several read passes.
        config()->set('kinetix.imports.spreadsheet_chunk_size', 25);

        $options = new ImportOptionsData;

        $this->assertSame(['name', 'email'], FileReader::headers($path, $options));
        $this->assertSame(120, FileReader::countRows($path, $options));

        $rows = iterator_to_array(FileReader::stream($path, $options), false);

        $this->assertCount(120, $rows);
        $this->assertSame(['Row 1', 'row1@x.com'], $rows[0]);
        $this->assertSame(['Row 120', 'row120@x.com'], $rows[119]);
    }

    public function test_the_queued_job_imports_every_row_across_chunk_boundaries(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kinetix-imports/big.csv', $this->rowsCsv(20));
        CountingImporter::$rows = 0;

        (new ImportProcessor(
            CountingImporter::class,
            'kinetix-imports/big.csv',
            [],
            ['name' => 0, 'email' => 1],
        ))->handle();

        // 20 rows through a chunk size of 3 — nothing dropped at a boundary.
        $this->assertSame(20, CountingImporter::$rows);
        Storage::disk('local')->assertMissing('kinetix-imports/big.csv');
    }

    public function test_importer_settings_fall_back_to_config_and_are_overridable(): void
    {
        config()->set('kinetix.imports.preview_rows', 25);
        config()->set('kinetix.imports.preview_columns', 6);
        config()->set('kinetix.imports.layout', 'auto');

        $inherited = (new WideImporter)->settings();

        $this->assertTrue($inherited->hasPreview);
        $this->assertSame(25, $inherited->previewRows);
        $this->assertSame(6, $inherited->previewColumns);
        $this->assertSame('auto', $inherited->layout);

        $overridden = (new TinyPreviewImporter)->settings();

        $this->assertSame(2, $overridden->previewRows);
        $this->assertSame(3, $overridden->previewColumns);
        $this->assertSame('fullscreen', $overridden->layout);
        $this->assertSame(512, $overridden->maxUploadSize);
    }

    public function test_an_unknown_layout_falls_back_to_auto(): void
    {
        config()->set('kinetix.imports.layout', 'carousel');

        $this->assertSame('auto', (new WideImporter)->getLayout());
    }

    public function test_a_preview_less_importer_reports_no_preview(): void
    {
        $this->assertFalse((new NoPreviewImporter)->settings()->hasPreview);
    }

    public function test_exact_match_requires_every_column_and_every_header_to_line_up(): void
    {
        $headers = ['Name', 'Email'];

        $this->assertTrue(
            WideImporter::isExactMatch($headers, WideImporter::guessMapping($headers))
        );

        // An extra source column means something would be silently dropped.
        $withExtra = ['Name', 'Email', 'Notes'];
        $this->assertFalse(
            WideImporter::isExactMatch($withExtra, WideImporter::guessMapping($withExtra))
        );

        // A missing target column is not a match either.
        $partial = ['Name'];
        $this->assertFalse(
            WideImporter::isExactMatch($partial, WideImporter::guessMapping($partial))
        );
    }

    public function test_blank_headers_never_count_as_an_exact_match(): void
    {
        $this->assertFalse(WideImporter::isExactMatch(['', ' '], ['name' => 0, 'email' => 1]));
    }

    protected function rowsCsv(int $rows): string
    {
        $csv = "name,email\n";

        for ($i = 1; $i <= $rows; $i++) {
            $csv .= "Row {$i},row{$i}@x.com\n";
        }

        return $csv;
    }

    protected function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kx').'.csv';
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    protected function xlsx(int $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['name', 'email'], null, 'A1');

        for ($i = 1; $i <= $rows; $i++) {
            $sheet->fromArray(["Row {$i}", "row{$i}@x.com"], null, 'A'.($i + 1));
        }

        $path = tempnam(sys_get_temp_dir(), 'kx').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->paths[] = $path;

        return $path;
    }
}
