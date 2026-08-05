<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\ExportAction;
use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\Jobs\ExportProcessor;
use Happones\Kinetix\Imports\ImportColumn;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class NotifyRecipient extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class NotifyWidget extends Model
{
    protected $table = 'notify_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class NotifyWidgetExporter extends Exporter
{
    protected static ?string $model = NotifyWidget::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')];
    }
}

class FlakyRowExporter extends NotifyWidgetExporter
{
    public function mapRecord(Model $record): array
    {
        if ($record->getAttribute('name') === 'Bob') {
            throw new RuntimeException('unmappable record');
        }

        return parent::mapRecord($record);
    }
}

class CustomMessageExporter extends NotifyWidgetExporter
{
    public function getStartedNotificationBody(): string
    {
        return 'Custom export queued';
    }

    public function getCompletedNotificationTitle(int $exported, int $failed): string
    {
        return "Done: {$exported} rows";
    }

    public function getCompletedNotificationBody(int $exported, int $failed): string
    {
        return 'Custom export body';
    }

    public function getFailedNotificationTitle(): string
    {
        return 'Custom export failed';
    }
}

class NotifyWidgetImporter extends Importer
{
    protected static ?string $model = NotifyWidget::class;

    public static function getColumns(): array
    {
        return [ImportColumn::make('name')];
    }
}

class FlakyRowImporter extends NotifyWidgetImporter
{
    public function importRow(array $data): void
    {
        if (($data['name'] ?? null) === 'Bob') {
            throw new RuntimeException('bad row');
        }

        parent::importRow($data);
    }
}

class CustomMessageImporter extends NotifyWidgetImporter
{
    public function getStartedNotificationBody(): string
    {
        return 'Custom import queued';
    }

    public function getCompletedNotificationTitle(int $imported, int $failed): string
    {
        return 'Custom import done';
    }

    public function getCompletedNotificationBody(int $imported, int $failed): string
    {
        return "{$imported} in, {$failed} skipped";
    }

    public function getFailedNotificationTitle(): string
    {
        return 'Custom import failed';
    }
}

class ImportExportNotificationTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // KinetixLaravelNotification is ShouldQueue: run it inline so the
        // database notification asserted below is written directly. The
        // testbench `package:test` skeleton defaults to the database queue,
        // which would require a `jobs` table this test doesn't create.
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notify_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Storage::fake('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function latestNotificationData(NotifyRecipient $user): array
    {
        $notification = $user->fresh()->notifications()->first();

        $this->assertNotNull($notification, 'A database notification should have been sent');

        return $notification->data;
    }

    public function test_export_completion_sends_a_database_notification_with_a_download_action(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);
        NotifyWidget::create(['name' => 'Ada']);

        (new ExportProcessor(NotifyWidgetExporter::class, NotifyRecipient::class, $user->id))->handle();

        $data = $this->latestNotificationData($user);

        $this->assertSame((string) __('kinetix.export_ready'), $data['title']);
        $this->assertSame('success', $data['status']);
        $this->assertStringContainsString(
            '/exports/download',
            (string) json_encode($data['actions'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_export_skips_failing_rows_and_reports_them_as_a_warning(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);
        NotifyWidget::create(['name' => 'Ada']);
        NotifyWidget::create(['name' => 'Bob']);
        NotifyWidget::create(['name' => 'Cleo']);

        (new ExportProcessor(FlakyRowExporter::class, NotifyRecipient::class, $user->id))->handle();

        $contents = Storage::disk('local')->get(
            Storage::disk('local')->files('kinetix-exports')[0],
        );

        // One bad row must not cost the user the rest of the export.
        $this->assertStringContainsString('Ada', $contents);
        $this->assertStringContainsString('Cleo', $contents);
        $this->assertStringNotContainsString('Bob', $contents);

        $data = $this->latestNotificationData($user);

        $this->assertSame('warning', $data['status']);
        // The builder's body() lands on the payload's `description` field.
        $this->assertStringContainsString(
            (string) __('kinetix.export_failed_rows', ['count' => 1]),
            $data['description'],
        );
    }

    public function test_export_completion_messages_are_customizable_per_exporter(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);
        NotifyWidget::create(['name' => 'Ada']);

        (new ExportProcessor(CustomMessageExporter::class, NotifyRecipient::class, $user->id))->handle();

        $data = $this->latestNotificationData($user);

        $this->assertSame('Done: 1 rows', $data['title']);
        $this->assertSame('Custom export body', $data['description']);
    }

    public function test_export_failure_notification_is_customizable_per_exporter(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);

        (new ExportProcessor(CustomMessageExporter::class, NotifyRecipient::class, $user->id))
            ->failed(new RuntimeException('disk on fire'));

        $data = $this->latestNotificationData($user);

        $this->assertSame('Custom export failed', $data['title']);
        $this->assertSame('danger', $data['status']);
    }

    public function test_export_action_queued_toast_uses_the_exporter_started_message(): void
    {
        $data = ExportAction::make()->exporter(CustomMessageExporter::class)->toData();

        $this->assertSame('Custom export queued', $data->httpRequest['toast'] ?? null);
    }

    public function test_import_completion_messages_are_customizable_per_importer(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);
        Storage::disk('local')->put('kinetix-imports/widgets.csv', "name\nAda\n");

        (new ImportProcessor(
            CustomMessageImporter::class,
            'kinetix-imports/widgets.csv',
            ['delimiter' => ',', 'enclosure' => '"', 'skipLines' => 0, 'hasHeader' => true],
            ['name'      => 0],
            NotifyRecipient::class,
            $user->id,
        ))->handle();

        $this->assertDatabaseHas('notify_widgets', ['name' => 'Ada']);

        $data = $this->latestNotificationData($user);

        $this->assertSame('Custom import done', $data['title']);
        $this->assertSame('1 in, 0 skipped', $data['description']);
        $this->assertSame('success', $data['status']);
    }

    public function test_import_failure_notification_is_customizable_per_importer(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);

        (new ImportProcessor(
            CustomMessageImporter::class,
            'kinetix-imports/widgets.csv',
            [],
            ['name' => 0],
            NotifyRecipient::class,
            $user->id,
        ))->failed(new RuntimeException('unreadable file'));

        $data = $this->latestNotificationData($user);

        $this->assertSame('Custom import failed', $data['title']);
        $this->assertSame('danger', $data['status']);
    }

    public function test_import_failures_are_downloadable_as_a_csv_of_the_failed_rows(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);
        Storage::disk('local')->put(
            'kinetix-imports/widgets.csv',
            "name\nAda\nCleo\n",
        );

        (new ImportProcessor(
            NotifyWidgetImporter::class,
            'kinetix-imports/widgets.csv',
            ['delimiter' => ',', 'enclosure' => '"', 'skipLines' => 0, 'hasHeader' => true],
            ['name'      => 0],
            NotifyRecipient::class,
            $user->id,
        ))->handle();

        // Rebind to the flaky importer variant: rerun with a fresh file.
        $user2 = NotifyRecipient::create(['name' => 'B']);
        Storage::disk('local')->put(
            'kinetix-imports/widgets2.csv',
            "name\nAda2\nBob\nCleo2\n",
        );

        (new ImportProcessor(
            FlakyRowImporter::class,
            'kinetix-imports/widgets2.csv',
            ['delimiter' => ',', 'enclosure' => '"', 'skipLines' => 0, 'hasHeader' => true],
            ['name'      => 0],
            NotifyRecipient::class,
            $user2->id,
        ))->handle();

        // A clean import must NOT produce a failed-rows artifact or action.
        $cleanData = $this->latestNotificationData($user);
        $this->assertSame([], $cleanData['actions']);

        // The flaky import skipped Bob but imported the rest.
        $this->assertDatabaseHas('notify_widgets', ['name' => 'Ada2']);
        $this->assertDatabaseMissing('notify_widgets', ['name' => 'Bob']);

        $data = $this->latestNotificationData($user2);
        $this->assertSame('warning', $data['status']);

        $action = $data['actions'][0] ?? null;
        $this->assertNotNull($action, 'The notification should carry a failed-rows download action');
        $this->assertSame((string) __('kinetix.download_failed_rows'), $action['label']);
        $this->assertStringContainsString('/exports/download', (string) $action['url']);

        // The CSV holds the failed source row, its number and the reason.
        $files = Storage::disk('local')->files('kinetix-exports');
        $this->assertCount(1, $files);

        $csv = Storage::disk('local')->get($files[0]);
        $this->assertStringContainsString(
            __('kinetix.import_failures_row_heading').',name,'.__('kinetix.import_failures_error_heading'),
            $csv,
        );
        $this->assertStringContainsString('Bob', $csv);
        $this->assertStringContainsString('bad row', $csv);
        $this->assertStringNotContainsString('Ada2', $csv);

        // The signed link streams the CSV for the recipient it was minted for.
        parse_str((string) parse_url((string) $action['url'], PHP_URL_QUERY), $query);

        $response = $this->actingAs($user2)->get(
            route('kinetix.exports.download', ['token' => $query['token']]),
        );

        $response->assertOk();
        $response->assertDownload('flaky-row-importer-failed-rows.csv');
        $this->assertStringContainsString('Bob', $response->streamedContent());
    }

    public function test_job_notifications_carry_the_team_stamp(): void
    {
        $user = NotifyRecipient::create(['name' => 'A']);
        NotifyWidget::create(['name' => 'Ada']);

        (new ExportProcessor(
            NotifyWidgetExporter::class,
            NotifyRecipient::class,
            $user->id,
            [],
            7,
        ))->handle();

        $this->assertSame(7, $this->latestNotificationData($user)['team']);
    }

    public function test_import_start_endpoint_returns_the_importer_started_message(): void
    {
        Queue::fake();

        $this->postJson(route('kinetix.imports.start'), [
            'importer'  => CustomMessageImporter::token(),
            'fileToken' => Crypt::encryptString('kinetix-imports/widgets.csv'),
            'mapping'   => ['name' => 0],
        ])->assertOk()->assertJson([
            'status'  => 'queued',
            'message' => 'Custom import queued',
        ]);
    }
}
