<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ImportAction;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PreviewImporter extends Importer
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

class CappedPreviewImporter extends PreviewImporter
{
    protected ?int $previewRows = 3;

    protected ?int $previewColumns = 2;

    protected ?string $layout = 'sheet';

    // Kilobytes — small enough for a test upload to exceed it.
    protected ?int $maxUploadSize = 1;
}

class SilentPreviewImporter extends PreviewImporter
{
    protected ?bool $preview = false;
}

/**
 * What the upload/preview endpoints hand the dialog: a bounded SAMPLE plus the
 * settings the dialog needs to present itself. The sample must stay bounded no
 * matter how big the file is — that is the whole reason previewing is cheap.
 */
class ImportPreviewTest extends TestCase
{
    protected function csv(int $rows = 50, int $columns = 2): UploadedFile
    {
        $headers = ['name', 'email'];

        for ($c = 3; $c <= $columns; $c++) {
            $headers[] = "extra{$c}";
        }

        $contents = implode(',', $headers)."\n";

        for ($i = 1; $i <= $rows; $i++) {
            $cells = ["Row {$i}", "row{$i}@x.com"];

            for ($c = 3; $c <= $columns; $c++) {
                $cells[] = "v{$i}-{$c}";
            }

            $contents .= implode(',', $cells)."\n";
        }

        return UploadedFile::fake()->createWithContent('people.csv', $contents);
    }

    public function test_the_preview_samples_only_the_configured_rows(): void
    {
        Storage::fake('local');
        config()->set('kinetix.imports.preview_rows', 4);

        $response = $this->post(route('kinetix.imports.upload'), [
            'importer' => PreviewImporter::token(),
            'file'     => $this->csv(500),
        ])->assertOk();

        // 500 rows in the file, 4 parsed — the count is reported without a parse.
        $response->assertJsonCount(4, 'rows');
        $response->assertJsonPath('totalRows', 500);
        $response->assertJsonPath('settings.previewRows', 4);
    }

    public function test_per_importer_settings_reach_the_dialog(): void
    {
        Storage::fake('local');

        $this->post(route('kinetix.imports.upload'), [
            'importer' => CappedPreviewImporter::token(),
            'file'     => $this->csv(20),
        ])
            ->assertOk()
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('settings.previewColumns', 2)
            ->assertJsonPath('settings.layout', 'sheet')
            ->assertJsonPath('settings.maxUploadSize', 1);
    }

    public function test_an_importer_without_a_preview_returns_no_sample_rows_but_still_maps(): void
    {
        Storage::fake('local');

        $this->post(route('kinetix.imports.upload'), [
            'importer' => SilentPreviewImporter::token(),
            'file'     => $this->csv(20),
        ])
            ->assertOk()
            ->assertJsonPath('settings.hasPreview', false)
            ->assertJsonCount(0, 'rows')
            // The headers and the mapping are what the dialog actually needs.
            ->assertJsonPath('headers', ['name', 'email'])
            ->assertJsonPath('autoMapping.name', 0);
    }

    public function test_a_file_that_lines_up_one_for_one_is_reported_as_an_exact_match(): void
    {
        Storage::fake('local');

        $this->post(route('kinetix.imports.upload'), [
            'importer' => PreviewImporter::token(),
            'file'     => $this->csv(5),
        ])
            ->assertOk()
            ->assertJsonPath('isExactMatch', true);

        // An unclaimed source column means the dialog must still ask.
        $this->post(route('kinetix.imports.upload'), [
            'importer' => PreviewImporter::token(),
            'file'     => $this->csv(5, 4),
        ])
            ->assertOk()
            ->assertJsonPath('isExactMatch', false);
    }

    public function test_the_upload_size_ceiling_comes_from_the_importer(): void
    {
        Storage::fake('local');

        // ~4 KB against the importer's 1 KB ceiling.
        $this->postJson(route('kinetix.imports.upload'), [
            'importer' => CappedPreviewImporter::token(),
            'file'     => UploadedFile::fake()->create('big.csv', 4),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // The same file is fine for an importer that inherits the config ceiling.
        $this->post(route('kinetix.imports.upload'), [
            'importer' => PreviewImporter::token(),
            'file'     => $this->csv(2),
        ])->assertOk();
    }

    public function test_an_unauthorized_caller_is_refused_before_the_file_is_validated(): void
    {
        Storage::fake('local');

        // An oversized file for an INVALID importer must still report the
        // importer problem, not leak that the upload was even considered.
        $this->postJson(route('kinetix.imports.upload'), [
            'importer' => 'garbage-token',
            'file'     => UploadedFile::fake()->create('big.csv', 4096),
        ])->assertStatus(422)->assertJsonPath('message', __('kinetix.import_invalid'));
    }

    public function test_import_action_carries_the_dialog_settings_in_the_dispatch(): void
    {
        $data = ImportAction::make()->importer(CappedPreviewImporter::class)->toData();

        $this->assertSame('sheet', $data->dispatchData['settings']['layout']);
        $this->assertSame(3, $data->dispatchData['settings']['previewRows']);
        $this->assertSame(2, $data->dispatchData['settings']['previewColumns']);
        $this->assertSame('CappedPreviewImporter.csv', $data->dispatchData['settings']['template']);
    }
}
