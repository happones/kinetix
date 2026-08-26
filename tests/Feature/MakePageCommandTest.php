<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * `kinetix:make-page` scaffolds a BLANK page: the chrome (heading + both action
 * bars) declared in PHP, and a Vue page whose body is the implementer's to fill.
 */
class MakePageCommandTest extends TestCase
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

    protected function paths(string $name): array
    {
        $paths = [
            'page'       => app_path("Kinetix/Pages/{$name}Page.php"),
            'controller' => app_path("Http/Controllers/Kinetix/{$name}Controller.php"),
            'view'       => resource_path("js/pages/Kinetix/{$name}.vue"),
        ];

        $this->written = array_merge($this->written, array_values($paths));

        return $paths;
    }

    public function test_it_scaffolds_the_page_class_the_controller_and_the_vue_page(): void
    {
        $paths = $this->paths('InventoryAdjust');

        $this->artisan('kinetix:make-page', ['name' => 'InventoryAdjust'])->assertSuccessful();

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }

        $page = File::get($paths['page']);
        $this->assertStringContainsString('namespace App\Kinetix\Pages;', $page);
        $this->assertStringContainsString('class InventoryAdjustPage extends Page', $page);
        $this->assertStringContainsString("protected ?string \$heading = 'Inventory Adjust';", $page);
        $this->assertStringContainsString('protected function buildHeaderActions(): array', $page);
        $this->assertStringContainsString('protected function buildFooterActions(): array', $page);
        // The scaffold must model localizable labels, not raw literals.
        $this->assertStringContainsString('__(', $page);

        $controller = File::get($paths['controller']);
        $this->assertStringContainsString('InventoryAdjustPage::make()->toArray()', $controller);
        $this->assertStringContainsString("inertia('Kinetix/InventoryAdjust'", $controller);

        $view = File::get($paths['view']);
        $this->assertStringContainsString('<KinetixPageShell :page="page">', $view);
        $this->assertStringContainsString('KinetixPageData', $view);
    }

    public function test_the_generated_php_is_syntactically_valid(): void
    {
        $paths = $this->paths('ReportBuilder');

        $this->artisan('kinetix:make-page', ['name' => 'ReportBuilder'])->assertSuccessful();

        // A heredoc whose indentation drifts produces a file that parses as
        // garbage; lint both generated PHP files rather than trusting the eye.
        foreach (['page', 'controller'] as $key) {
            exec('php -l '.escapeshellarg($paths[$key]).' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
        }
    }

    public function test_every_generated_file_ends_in_a_newline(): void
    {
        $paths = $this->paths('Trailing');

        $this->artisan('kinetix:make-page', ['name' => 'Trailing'])->assertSuccessful();

        // Otherwise the scaffold fails the host's own Pint/ESLint on the very
        // first run, which is a bad first impression for generated code.
        foreach ($paths as $label => $path) {
            $this->assertStringEndsWith("\n", File::get($path), $label);
            $this->assertStringEndsNotWith("\n\n", File::get($path), $label);
        }
    }

    public function test_the_page_name_is_normalized_and_page_is_not_doubled(): void
    {
        $paths = $this->paths('Inventory');

        // `InventoryPage` must not become `InventoryPagePage`.
        $this->artisan('kinetix:make-page', ['name' => 'InventoryPage'])->assertSuccessful();

        $this->assertFileExists($paths['page']);
        $this->assertStringContainsString(
            'class InventoryPage extends Page',
            File::get($paths['page'])
        );
    }

    public function test_sticky_footer_is_opt_in(): void
    {
        $plain = $this->paths('PlainFooter');
        $this->artisan('kinetix:make-page', ['name' => 'PlainFooter'])->assertSuccessful();
        $this->assertStringNotContainsString('$stickyFooter', File::get($plain['page']));

        $sticky = $this->paths('PinnedFooter');
        $this->artisan('kinetix:make-page', [
            'name'            => 'PinnedFooter',
            '--sticky-footer' => true,
        ])->assertSuccessful();
        $this->assertStringContainsString(
            'protected bool $stickyFooter = true;',
            File::get($sticky['page'])
        );
    }

    public function test_the_controller_and_view_can_be_skipped(): void
    {
        $paths = $this->paths('ClassOnly');

        $this->artisan('kinetix:make-page', [
            'name'            => 'ClassOnly',
            '--no-controller' => true,
            '--no-view'       => true,
        ])->assertSuccessful();

        $this->assertFileExists($paths['page']);
        $this->assertFileDoesNotExist($paths['controller']);
        $this->assertFileDoesNotExist($paths['view']);
    }

    public function test_existing_files_are_not_overwritten_without_force(): void
    {
        $paths = $this->paths('Guarded');

        File::ensureDirectoryExists(dirname($paths['page']));
        File::put($paths['page'], '<?php // mine');

        $this->artisan('kinetix:make-page', ['name' => 'Guarded'])->assertSuccessful();
        $this->assertSame('<?php // mine', File::get($paths['page']));

        $this->artisan('kinetix:make-page', ['name' => 'Guarded', '--force' => true])
            ->assertSuccessful();
        $this->assertStringContainsString('extends Page', File::get($paths['page']));
    }
}
