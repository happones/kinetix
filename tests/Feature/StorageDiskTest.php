<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Support\KinetixDisk;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DiskWidget extends Model
{
    protected $table = 'disk_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class DiskWidgetExporter extends Exporter
{
    protected static ?string $model = DiskWidget::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }

    // No custom query() override: the framework auto-scopes a bulk export to the
    // selected `ids` via Exporter::resolveExportQuery().
}

class StorageDiskTest extends TestCase
{
    public function test_kinetix_disk_name_reads_config(): void
    {
        config()->set('kinetix.filesystem.disk', 's3');
        $this->assertSame('s3', KinetixDisk::name());
    }

    public function test_export_writes_to_the_configured_disk(): void
    {
        Schema::create('disk_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        DiskWidget::create(['name' => 'Ada']);

        Storage::fake('public');
        config()->set('kinetix.filesystem.disk', 'public');

        (new ExportProcessor(DiskWidgetExporter::class))->handle();

        $files = Storage::disk('public')->files('kinetix-exports');
        $this->assertNotEmpty($files, 'Export file should be stored on the configured disk');
        $this->assertStringContainsString('Ada', Storage::disk('public')->get($files[0]));
    }

    public function test_export_scopes_to_selected_ids_via_parameters(): void
    {
        Schema::create('disk_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        $ada = DiskWidget::create(['name' => 'Ada']);
        DiskWidget::create(['name' => 'Bob']);
        $cleo = DiskWidget::create(['name' => 'Cleo']);

        Storage::fake('public');
        config()->set('kinetix.filesystem.disk', 'public');
        // export() dispatches the processor; run it inline so the assertions
        // below see the written file (no `jobs` table in the test database).
        config()->set('queue.default', 'sync');

        // Mirrors a bulk action passing the selected ids to the export route.
        (new DiskWidgetExporter)->export(null, ['ids' => [$ada->id, $cleo->id]]);

        $contents = Storage::disk('public')->get(
            Storage::disk('public')->files('kinetix-exports')[0],
        );

        $this->assertStringContainsString('Ada', $contents);
        $this->assertStringContainsString('Cleo', $contents);
        $this->assertStringNotContainsString('Bob', $contents);
    }

    public function test_local_readable_path_returns_readable_content(): void
    {
        // Faked disks are local-backed, so path() yields a real file (no temp);
        // either way the returned path must be readable with the right content.
        Storage::fake('local');
        Storage::disk('local')->put('kinetix-imports/file.csv', "name\nBob");

        [$local, $isTemp] = KinetixDisk::localReadablePath('local', 'kinetix-imports/file.csv');

        $this->assertFalse($isTemp);
        $this->assertFileExists($local);
        $this->assertStringContainsString('Bob', (string) file_get_contents($local));
    }

    public function test_discard_temp_removes_only_temporary_files(): void
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'kx_');
        KinetixDisk::discardTemp($temp, true);
        $this->assertFileDoesNotExist($temp);

        $kept = (string) tempnam(sys_get_temp_dir(), 'kx_');
        KinetixDisk::discardTemp($kept, false);
        $this->assertFileExists($kept);
        @unlink($kept);
    }
}
