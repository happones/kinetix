<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ExportAction;
use Happones\Kinetix\Actions\ImportAction;
use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;

class ActionExporter extends Exporter
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }
}

class ActionImporter extends Importer
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ImportColumn::make('name')];
    }
}

class QueuedExporter extends Exporter
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }

    public function queue(): ?string
    {
        return 'exports';
    }
}

class ExportActionTest extends TestCase
{
    public function test_export_action_uses_a_background_request_not_an_inertia_visit(): void
    {
        $data = ExportAction::make()->exporter(ActionExporter::class)->toData();

        $this->assertNotNull($data);
        // Background HTTP request (avoids Inertia's response modal), not inertiaVisit.
        $this->assertNull($data->inertiaVisit);
        $this->assertSame('post', $data->httpRequest['method'] ?? null);
        $this->assertNotEmpty($data->httpRequest['toast'] ?? null);
        $this->assertStringContainsString('/exports/start', (string) $data->url);
        $this->assertStringContainsString('exporter=', (string) $data->url);
        $this->assertSame('download', $data->icon);
    }

    public function test_start_endpoint_dispatches_the_export(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.exports.start'), [
            'exporter' => ActionExporter::token(),
        ])->assertOk();

        Queue::assertPushed(ExportProcessor::class);
    }

    public function test_start_endpoint_rejects_an_invalid_exporter_token(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.exports.start'), [
            'exporter' => 'not-a-valid-token',
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_export_uses_the_default_queue_not_the_connection_name(): void
    {
        Queue::fake();

        (new ActionExporter)->export();

        // Regression: must NOT pin the connection name ('redis'/'sync') as a queue —
        // null lets the worker use the connection's default queue.
        Queue::assertPushed(ExportProcessor::class, fn ($job) => $job->queue === null);
    }

    public function test_export_honours_a_custom_queue(): void
    {
        Queue::fake();

        (new QueuedExporter)->export();

        Queue::assertPushed(ExportProcessor::class, fn ($job) => $job->queue === 'exports');
    }

    public function test_import_action_dispatches_open_importer_with_the_importer_token(): void
    {
        $data = ImportAction::make()->importer(ActionImporter::class)->toData();

        $this->assertNotNull($data);
        $this->assertSame('open-importer', $data->dispatchEvent);
        $this->assertArrayHasKey('importer', $data->dispatchData);
        $this->assertSame('upload', $data->icon);
    }
}
