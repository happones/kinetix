<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;

class StartImporter extends Importer
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

class TenantImporter extends StartImporter
{
    public function context(Request $request): array
    {
        // A real app reads the tenant here (e.g. $request->user()?->currentTeam).
        return ['team_id' => (int) $request->input('team_id')];
    }
}

class ImportStartTest extends TestCase
{
    private function fileToken(): string
    {
        return Crypt::encryptString('kinetix-imports/sample.csv');
    }

    public function test_valid_mapping_dispatches_the_import_job(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.imports.start'), [
            'importer'  => StartImporter::token(),
            'fileToken' => $this->fileToken(),
            'mapping'   => ['name' => 0, 'email' => 1],
        ])->assertOk();

        Queue::assertPushed(ImportProcessor::class);
    }

    public function test_the_importers_request_context_is_captured_into_the_job(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.imports.start'), [
            'importer'  => TenantImporter::token(),
            'fileToken' => $this->fileToken(),
            'mapping'   => ['name' => 0],
            'team_id'   => 42,
        ])->assertOk();

        Queue::assertPushed(ImportProcessor::class, function (ImportProcessor $job): bool {
            $context = new ReflectionProperty($job, 'context');

            return $context->getValue($job) === ['team_id' => 42];
        });
    }

    public function test_missing_required_mapping_is_rejected(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.imports.start'), [
            'importer'  => StartImporter::token(),
            'fileToken' => $this->fileToken(),
            'mapping'   => ['email' => 1], // required 'name' not mapped
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_required_column_mapped_to_null_is_rejected(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.imports.start'), [
            'importer'  => StartImporter::token(),
            'fileToken' => $this->fileToken(),
            'mapping'   => ['name' => null, 'email' => 1], // null counts as unmapped
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }
}
