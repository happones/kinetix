<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\HostKeyColumns;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * An install migrated before 0.219.15 has char(26) user_id / team_id columns
 * holding the numbers of an integer-keyed User/Team. The doctor names them and
 * kinetix:fix-key-columns writes the migration that converts them.
 */
class FixKeyColumnsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['kinetix.key_types.user' => 'bigint', 'kinetix.key_types.team' => 'bigint']);

        // What the old detection created for a model with a public ULID.
        Schema::create('kinetix_saved_views', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 26)->index();
            $table->char('team_id', 26)->nullable();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        File::delete(File::glob(database_path('migrations/*_fix_kinetix_host_key_columns.php')));

        parent::tearDown();
    }

    public function test_the_package_migrations_declare_the_host_key_columns(): void
    {
        $declared = collect(HostKeyColumns::declared())->map(fn (array $c): string => "{$c['table']}.{$c['column']}:{$c['kind']}");

        $this->assertContains('kinetix_saved_views.user_id:user', $declared);
        $this->assertContains('kinetix_saved_views.team_id:team', $declared);
        $this->assertContains('kinetix_report_runs.launched_by_id:user', $declared);
    }

    public function test_it_finds_the_text_columns_and_writes_a_migration_that_converts_them(): void
    {
        DB::table('kinetix_saved_views')->insert([
            ['user_id' => '7', 'team_id' => '3', 'name' => 'Mine'],
            ['user_id' => '12', 'team_id' => null, 'name' => 'Teamless'],
        ]);

        $this->assertSame(
            ['kinetix_saved_views.user_id', 'kinetix_saved_views.team_id'],
            array_map(fn (array $c): string => "{$c['table']}.{$c['column']}", HostKeyColumns::mismatched()),
        );

        $this->artisan('kinetix:doctor')->expectsOutputToContain('key column(s) don\'t match the app\'s keys');

        $this->artisan('kinetix:fix-key-columns')->assertSuccessful();

        $file = collect(File::glob(database_path('migrations/*_fix_kinetix_host_key_columns.php')))->sole();
        $this->assertStringContainsString("\$table->unsignedBigInteger('user_id')->change();", File::get($file));
        $this->assertStringContainsString("\$table->unsignedBigInteger('team_id')->nullable()->change();", File::get($file));

        (require $file)->up();

        $this->assertSame([], HostKeyColumns::mismatched());
        $this->assertEquals([7, 12], DB::table('kinetix_saved_views')->orderBy('id')->pluck('user_id')->all());
        $this->assertNull(DB::table('kinetix_saved_views')->where('name', 'Teamless')->value('team_id'));
    }

    public function test_a_column_holding_anything_but_numbers_is_left_alone(): void
    {
        DB::table('kinetix_saved_views')->insert(['user_id' => '01JA2B3C4D5E6F7G8H9J0K1M2N', 'team_id' => '3', 'name' => 'Real ULID']);

        $this->artisan('kinetix:fix-key-columns')
            ->expectsOutputToContain("aren't numbers")
            ->assertSuccessful();

        $file = collect(File::glob(database_path('migrations/*_fix_kinetix_host_key_columns.php')))->sole();
        $this->assertStringNotContainsString("'user_id'", File::get($file));
        $this->assertStringContainsString("'team_id'", File::get($file));
    }

    public function test_nothing_to_fix_says_so_and_writes_nothing(): void
    {
        Schema::drop('kinetix_saved_views');

        $this->artisan('kinetix:fix-key-columns')
            ->expectsOutputToContain('matches the app\'s keys')
            ->assertSuccessful();

        $this->assertSame([], File::glob(database_path('migrations/*_fix_kinetix_host_key_columns.php')));
    }
}
