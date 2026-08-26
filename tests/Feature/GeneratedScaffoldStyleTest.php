<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Commands\Concerns\WritesGeneratedFiles;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Generated code must be clean under the HOST's own formatter the moment it
 * lands.
 *
 * The regression: stubs are heredocs, and a heredoc ends at its closing marker
 * with no trailing newline — so scaffolded files failed the HOST's own
 * `pint` (`single_blank_line_at_eof`) and `prettier` on the very first run.
 * Generated code that arrives already violating the project's standards is a bad
 * first impression, and the fix is easy to lose again the next time someone adds
 * a stub, so it is pinned here for every generator at once.
 *
 * @see WritesGeneratedFiles
 */
class GeneratedScaffoldStyleTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    protected array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            File::delete($path);
        }

        $this->written = [];

        parent::tearDown();
    }

    /**
     * Every single-class generator, with the file each one writes.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function generators(): array
    {
        return [
            'action'           => ['kinetix:make-action', ['name' => 'NewlineAction'], 'Kinetix/Actions/NewlineAction.php'],
            'table'            => ['kinetix:make-table', ['name' => 'NewlineTable'], 'Kinetix/Tables/NewlineTable.php'],
            'form'             => ['kinetix:make-form', ['name' => 'NewlineForm'], 'Kinetix/Forms/NewlineForm.php'],
            'infolist'         => ['kinetix:make-infolist', ['name' => 'NewlineInfolist'], 'Kinetix/Infolists/NewlineInfolist.php'],
            'exporter'         => ['kinetix:make-exporter', ['name' => 'NewlineExporter'], 'Kinetix/Exporters/NewlineExporter.php'],
            'importer'         => ['kinetix:make-importer', ['name' => 'NewlineImporter'], 'Kinetix/Importers/NewlineImporter.php'],
            'settings page'    => ['kinetix:make-settings-page', ['name' => 'NewlineSettingsPage'], 'Kinetix/Settings/NewlineSettingsPage.php'],
            'relation manager' => ['kinetix:make-relation-manager', ['name' => 'NewlineRelationManager'], 'Kinetix/RelationManagers/NewlineRelationManager.php'],
            'report'           => ['kinetix:make-report', ['name' => 'NewlineReport'], 'Kinetix/Reports/NewlineReport.php'],
            'notification'     => ['kinetix:make-notification', ['name' => 'NewlineNotification'], 'Kinetix/Notifications/NewlineNotification.php'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('generators')]
    public function test_generated_files_end_in_exactly_one_newline(
        string $command,
        array $arguments,
        string $relativeAppPath,
    ): void {
        $path            = app_path($relativeAppPath);
        $this->written[] = $path;

        $this->artisan($command, $arguments)->assertSuccessful();

        $this->assertFileExists($path, "{$command} wrote nothing at {$relativeAppPath}");

        $contents = File::get($path);
        $this->assertStringEndsWith("\n", $contents, "{$command}: no trailing newline");
        $this->assertStringEndsNotWith("\n\n", $contents, "{$command}: blank line(s) at EOF");
    }

    public function test_the_page_scaffold_ends_every_file_in_one_newline(): void
    {
        $paths = [
            app_path('Kinetix/Pages/NewlineProbePage.php'),
            app_path('Http/Controllers/Kinetix/NewlineProbeController.php'),
            resource_path('js/pages/Kinetix/NewlineProbe.vue'),
        ];
        $this->written = array_merge($this->written, $paths);

        $this->artisan('kinetix:make-page', ['name' => 'NewlineProbe'])->assertSuccessful();

        foreach ($paths as $path) {
            $contents = File::get($path);
            $this->assertStringEndsWith("\n", $contents, $path);
            $this->assertStringEndsNotWith("\n\n", $contents, $path);
        }
    }

    public function test_the_normalizer_collapses_any_trailing_whitespace(): void
    {
        $normalize = static function (string $value): string {
            return (new class
            {
                use WritesGeneratedFiles;

                public function run(string $value): string
                {
                    return self::normalizeGenerated($value);
                }
            })->run($value);
        };

        $this->assertSame("a\n", $normalize('a'));
        $this->assertSame("a\n", $normalize("a\n"));
        $this->assertSame("a\n", $normalize("a\n\n\n"));
        $this->assertSame("a\n", $normalize("a  \n \t\n"));
        // Interior blank lines are untouched — only the tail is normalized.
        $this->assertSame("a\n\nb\n", $normalize("a\n\nb"));
    }

    /**
     * The strong guard: Pint itself, over what the generators actually wrote.
     *
     * A per-rule assertion only catches the rule someone thought of. Running the
     * formatter catches the whole class — and since the package and a typical
     * host both use the `laravel` preset, "Pint changes nothing" is the same
     * thing as "the scaffold survives the host's first commit".
     */
    public function test_pint_has_nothing_to_fix_in_any_generated_file(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $pint        = $packageRoot.'/vendor/bin/pint';

        if (! is_executable($pint)) {
            $this->markTestSkipped('Pint is not installed in this environment.');
        }

        $probe = sys_get_temp_dir().'/kinetix-generated-probe-'.getmypid();
        File::deleteDirectory($probe);
        File::ensureDirectoryExists($probe);

        foreach (static::generators() as [$command, $arguments, $relativeAppPath]) {
            $this->artisan($command, $arguments)->assertSuccessful();

            $written         = app_path($relativeAppPath);
            $this->written[] = $written;

            File::copy($written, $probe.'/'.basename($written));
        }

        // The page scaffold's PHP too (its Vue file is not Pint's business).
        $this->artisan('kinetix:make-page', ['name' => 'PintProbe'])->assertSuccessful();
        foreach ([
            app_path('Kinetix/Pages/PintProbePage.php'),
            app_path('Http/Controllers/Kinetix/PintProbeController.php'),
            resource_path('js/pages/Kinetix/PintProbe.vue'),
        ] as $written) {
            $this->written[] = $written;

            if (str_ends_with($written, '.php')) {
                File::copy($written, $probe.'/'.basename($written));
            }
        }

        exec(
            escapeshellarg($pint).' --test --config '.escapeshellarg($packageRoot.'/pint.json')
                .' '.escapeshellarg($probe).' 2>&1',
            $output,
            $status
        );

        File::deleteDirectory($probe);

        $this->assertSame(
            0,
            $status,
            "A generator produced code the formatter wants to change:\n".implode("\n", $output)
        );
    }
}
