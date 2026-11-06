<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Flash\KinetixFlash;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ScaffoldRuntimeUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class ScaffoldRuntimeModel extends Model
{
    use SoftDeletes;

    protected $table = 'scaffold_runtime_posts';

    protected $guarded = [];
}

/**
 * Generates every scaffold variant, loads it, registers the routes the command
 * prints and drives the generated controllers over HTTP, so a template that
 * lints but fails at runtime (a missing import, a mis-bound team segment) fails
 * here.
 */
class ScaffoldRuntimeTest extends TestCase
{
    /** @var array<string, array<string, bool>> */
    private const VARIANTS = [
        'Srfull'           => [],
        'Srfullsoft'       => ['--soft-deletes' => true],
        'Srsimple'         => ['--simple' => true],
        'Srsimplesoft'     => ['--simple' => true, '--soft-deletes' => true],
        'Srsimplesoftteam' => ['--simple' => true, '--soft-deletes' => true, '--team' => true],
        'Srfullsoftteam'   => ['--soft-deletes' => true, '--team' => true],
    ];

    private const NS = 'App\\Http\\Controllers\\Kinetix\\';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('App\\Http\\Controllers\\Controller')) {
            eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        }

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('scaffold_runtime_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('team_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        foreach (self::VARIANTS as $name => $options) {
            if (! class_exists("App\\Models\\{$name}")) {
                class_alias(ScaffoldRuntimeModel::class, "App\\Models\\{$name}");
            }

            $this->artisan('kinetix:make-resource', ['name' => $name, '--force' => true] + $options)->assertSuccessful();

            require_once app_path("Kinetix/Resources/{$name}Resource.php");
            require_once app_path("Http/Controllers/Kinetix/{$name}Controller.php");
        }

        $this->registerRoutes();
        $this->actingAs(ScaffoldRuntimeUser::create(['name' => 'x']));
        $this->withoutExceptionHandling();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::VARIANTS) as $name) {
            File::delete(app_path("Http/Controllers/Kinetix/{$name}Controller.php"));
            File::deleteDirectory(resource_path('js/pages/Kinetix/'.Str::plural($name)));
        }

        File::deleteDirectory(app_path('Kinetix'));

        parent::tearDown();
    }

    private function registerRoutes(): void
    {
        Route::middleware('web')->group(function (): void {
            foreach (['Srfull' => 'srfulls', 'Srfullsoft' => 'srfullsofts'] as $name => $slug) {
                Route::resource($slug, self::NS."{$name}Controller");
            }

            Route::get('srsimples', [self::NS.'SrsimpleController', 'index'])->name('srsimples.index');
            Route::get('srsimplesofts', [self::NS.'SrsimplesoftController', 'index'])->name('srsimplesofts.index');

            foreach (['Srfullsoft' => 'srfullsofts', 'Srsimplesoft' => 'srsimplesofts'] as $name => $slug) {
                $this->softDeleteRoutes($name, $slug);
            }

            Route::prefix('{current_team}')->group(function (): void {
                Route::resource('srfullsoftteams', self::NS.'SrfullsoftteamController');
                Route::get('srsimplesoftteams', [self::NS.'SrsimplesoftteamController', 'index'])->name('srsimplesoftteams.index');

                foreach (['Srfullsoftteam' => 'srfullsoftteams', 'Srsimplesoftteam' => 'srsimplesoftteams'] as $name => $slug) {
                    $this->softDeleteRoutes($name, $slug);
                }
            });
        });

        Route::getRoutes()->refreshNameLookups();
    }

    private function softDeleteRoutes(string $name, string $slug): void
    {
        Route::post("{$slug}/{id}/restore", [self::NS."{$name}Controller", 'restore'])->name("{$slug}.restore");
        Route::delete("{$slug}/{id}/force-delete", [self::NS."{$name}Controller", 'forceDelete'])->name("{$slug}.force-delete");
    }

    /**
     * @param list<string|null> $seen
     */
    private function lastToastMessage(array &$seen): void
    {
        $toasts = KinetixFlash::flashed()['toasts'] ?? [];
        $seen[] = end($toasts)['message']           ?? null;
    }

    public function test_full_controllers_run_every_write_and_toast_the_generic_message(): void
    {
        foreach (['srfulls' => false, 'srfullsofts' => true, 'acme/srfullsoftteams' => true] as $path => $soft) {
            $seen = [];

            $this->post("/{$path}", ['title' => 'A'])->assertRedirect();
            $this->lastToastMessage($seen);
            $id = (int) DB::table('scaffold_runtime_posts')->max('id');

            $this->put("/{$path}/{$id}", ['title' => 'B'])->assertRedirect();
            $this->lastToastMessage($seen);
            $this->delete("/{$path}/{$id}")->assertRedirect();
            $this->lastToastMessage($seen);

            $expected = [__('kinetix.record_created'), __('kinetix.record_updated'), __('kinetix.record_deleted')];

            if ($soft) {
                $this->post("/{$path}/{$id}/restore")->assertRedirect();
                $this->lastToastMessage($seen);
                $this->delete("/{$path}/{$id}/force-delete")->assertRedirect();
                $this->lastToastMessage($seen);

                $expected[] = __('kinetix.record_restored');
                $expected[] = __('kinetix.record_force_deleted');
                $this->assertSame(0, DB::table('scaffold_runtime_posts')->where('id', $id)->count());
            }

            $this->assertSame($expected, $seen, $path);
        }
    }

    public function test_simple_controllers_restore_and_force_delete(): void
    {
        foreach (['srsimplesofts' => 'team-less', 'acme/srsimplesoftteams' => 'acme'] as $path => $team) {
            $seen  = [];
            $attrs = $team === 'acme' ? ['team_id' => 'acme'] : [];

            $id = DB::table('scaffold_runtime_posts')->insertGetId(['title' => 'S', 'deleted_at' => now()] + $attrs);
            $this->post("/{$path}/{$id}/restore")->assertRedirect("/{$path}");
            $this->lastToastMessage($seen);

            DB::table('scaffold_runtime_posts')->where('id', $id)->update(['deleted_at' => now()]);
            $this->delete("/{$path}/{$id}/force-delete")->assertRedirect("/{$path}");
            $this->lastToastMessage($seen);

            $this->assertSame([__('kinetix.record_restored'), __('kinetix.record_force_deleted')], $seen, $path);
            $this->assertSame(0, DB::table('scaffold_runtime_posts')->count());
        }
    }
}
