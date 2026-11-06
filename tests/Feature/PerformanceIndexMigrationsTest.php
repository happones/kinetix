<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The composite indexes behind the feeds and logs that grow without bound:
 * each migration is idempotent, honours an equivalent index the app already
 * has (by columns, in order — not by name), and only ever drops its own.
 */
class PerformanceIndexMigrationsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string, 4: string, 5: string}>
     */
    public static function migrations(): array
    {
        return [
            'activity'      => ['2026_01_01_000045_add_team_created_index_to_kinetix_activity_table.php', 'kinetix_activity', ['team_id', 'created_at'], 'kinetix_activity_team_created_index', 'kinetix-activity-migrations', 'activity'],
            'api logs'      => ['2026_01_01_000046_add_team_created_index_to_kinetix_api_logs_table.php', 'kinetix_api_logs', ['team_id', 'created_at'], 'kinetix_api_logs_team_created_index', 'kinetix-api-logs-migrations', 'api_logs'],
            'webhook logs'  => ['2026_01_01_000047_add_endpoint_created_index_to_kinetix_webhook_logs_table.php', 'kinetix_webhook_logs', ['webhook_endpoint_id', 'created_at'], 'kinetix_webhook_logs_endpoint_created_index', 'kinetix-webhooks-migrations', 'webhooks'],
            'report runs'   => ['2026_01_01_000048_add_team_created_index_to_kinetix_report_runs_table.php', 'kinetix_report_runs', ['team_id', 'created_at'], 'kinetix_report_runs_team_created_index', 'kinetix-reports-center-migrations', 'reports_center'],
            'conversations' => ['2026_01_01_000049_add_list_index_to_kinetix_conversations_table.php', 'kinetix_conversations', ['team_id', 'archived_at', 'last_message_at'], 'kinetix_conversations_list_index', 'kinetix-inbox-migrations', 'inbox'],
            'notifications' => ['2026_01_01_000050_add_kinetix_feed_index_to_notifications_table.php', 'notifications', ['notifiable_type', 'notifiable_id', 'created_at'], 'kinetix_notifications_feed_index', 'kinetix-notifications-migrations', 'notifications'],
        ];
    }

    protected function tearDown(): void
    {
        File::delete(File::glob(database_path('migrations/2026_01_01_0000*.php')));

        parent::tearDown();
    }

    private function migration(string $file): Migration
    {
        return require __DIR__.'/../../database/migrations/'.$file;
    }

    /**
     * @param list<string> $columns
     */
    private function createTable(string $table, array $columns): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->increments('id');

            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
        });
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_map(static fn (array $index): string => (string) $index['name'], Schema::getIndexes($table));
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('migrations')]
    public function test_it_creates_its_index_once_and_drops_only_its_own(string $file, string $table, array $columns, string $index, string $tag, string $module): void
    {
        $this->createTable($table, $columns);

        $this->migration($file)->up();
        $this->migration($file)->up();

        $this->assertSame(1, array_count_values($this->indexNames($table))[$index] ?? 0);
        $this->assertLessThanOrEqual(64, strlen($index));

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index([$columns[0]], 'app_own_index');
        });

        $this->migration($file)->down();
        $this->migration($file)->down();

        $this->assertNotContains($index, $this->indexNames($table));
        $this->assertContains('app_own_index', $this->indexNames($table));
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('migrations')]
    public function test_a_missing_table_or_column_is_skipped(string $file, string $table, array $columns, string $index, string $tag, string $module): void
    {
        $this->migration($file)->up();
        $this->assertFalse(Schema::hasTable($table));

        $this->createTable($table, array_slice($columns, 0, -1));
        $this->migration($file)->up();

        $this->assertNotContains($index, $this->indexNames($table));
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('migrations')]
    public function test_an_equivalent_index_of_the_app_counts_but_not_one_in_another_order(string $file, string $table, array $columns, string $index, string $tag, string $module): void
    {
        $this->createTable($table, $columns);

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index(array_reverse($columns), 'app_reversed_index');
        });

        $this->migration($file)->up();
        $this->assertContains($index, $this->indexNames($table));

        $this->migration($file)->down();

        // A longer index starting with the same columns, under the app's name.
        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index([...$columns, 'id'], 'app_equivalent_index');
        });

        $this->migration($file)->up();
        $this->assertNotContains($index, $this->indexNames($table));
    }

    #[DataProvider('migrations')]
    public function test_the_publish_tag_ships_the_migration(string $file, string $table, array $columns, string $index, string $tag, string $module): void
    {
        $targets = array_map('basename', array_values(ServiceProvider::pathsToPublish(KinetixServiceProvider::class, $tag)));

        $this->assertContains($file, $targets);
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('migrations')]
    public function test_doctor_flags_a_missing_index_and_is_quiet_once_it_exists(string $file, string $table, array $columns, string $index, string $tag, string $module): void
    {
        config($module === 'notifications' ? ['kinetix.notifications.database' => true] : ["kinetix.{$module}.enabled" => true]);

        $this->createTable($table, $columns);

        $this->artisan('kinetix:doctor', ['--json' => true])
            ->expectsOutputToContain("--tag={$tag}");

        $this->migration($file)->up();

        $this->artisan('kinetix:doctor', ['--json' => true])
            ->doesntExpectOutputToContain("--tag={$tag}");
    }

    public function test_upgrade_lists_the_new_migrations_of_adopted_tags_only(): void
    {
        File::ensureDirectoryExists(database_path('migrations'));
        File::copy(
            __DIR__.'/../../database/migrations/2026_01_01_000003_create_kinetix_activity_table.php',
            database_path('migrations/2026_01_01_000003_create_kinetix_activity_table.php'),
        );

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('New Kinetix migrations for kinetix-activity-migrations: 2026_01_01_000045_add_team_created_index_to_kinetix_activity_table.php')
            ->doesntExpectOutputToContain('kinetix-webhooks-migrations')
            ->doesntExpectOutputToContain('kinetix-notifications-migrations')
            ->assertSuccessful();

        File::copy(
            __DIR__.'/../../database/migrations/2026_01_01_000045_add_team_created_index_to_kinetix_activity_table.php',
            database_path('migrations/2026_01_01_000045_add_team_created_index_to_kinetix_activity_table.php'),
        );

        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('New Kinetix migrations')
            ->assertSuccessful();
    }
}
