<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * `resources/js/types/index.ts` is the **host's** barrel in the Laravel starter
 * kits (it re-exports ./auth, ./teams, …). Kinetix used to publish its own
 * declarations under that exact name, which deleted those re-exports; because
 * `@/types` still resolved, TypeScript silently degraded to `any` instead of
 * erroring, and component prop contracts stopped being checked.
 */
class PublishedTypesTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('js/types'));
        File::deleteDirectory(resource_path('js/components/kinetix'));
        File::deleteDirectory(resource_path('js/composables'));
        File::deleteDirectory(resource_path('js/stores'));
        File::deleteDirectory(resource_path('js/plugins'));
        File::deleteDirectory(resource_path('js/icons'));

        parent::tearDown();
    }

    public function test_the_declarations_publish_to_kinetix_ts(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        $this->assertFileExists(resource_path('js/types/kinetix.ts'));
        $this->assertStringContainsString(
            'KinetixAction',
            (string) File::get(resource_path('js/types/kinetix.ts')),
        );
    }

    public function test_an_existing_host_barrel_is_never_touched(): void
    {
        File::ensureDirectoryExists(resource_path('js/types'));
        File::put(resource_path('js/types/index.ts'), "export * from './auth';\n");

        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components', '--force' => true])->assertSuccessful();

        $this->assertSame(
            "export * from './auth';\n",
            (string) File::get(resource_path('js/types/index.ts')),
        );
    }

    public function test_the_package_ships_no_types_index_file(): void
    {
        // A regression guard: re-adding `types/index.ts` to the package would
        // silently reintroduce the clobbering, since the publish map copies the
        // file by name.
        $this->assertFileDoesNotExist(__DIR__.'/../../resources/js/types/index.ts');
        $this->assertFileExists(__DIR__.'/../../resources/js/types/kinetix.ts');
    }

    public function test_every_published_file_in_a_shared_directory_claims_a_kinetix_name(): void
    {
        // components/ gets its own `kinetix/` subdirectory, but composables,
        // stores, plugins and icons publish straight into directories the starter
        // kits already own. A generic filename there (stores/notifications.ts)
        // would be overwritten by `kinetix:upgrade` on every composer update —
        // silently, and forever after.
        // Only the TOP-LEVEL entry has to be namespaced: anything nested inside a
        // `kinetix`-named directory is already unreachable from a host path.
        $offenders = [];

        foreach (['composables', 'stores', 'plugins', 'icons'] as $directory) {
            $path = __DIR__.'/../../resources/js/'.$directory;

            if (! File::isDirectory($path)) {
                continue;
            }

            $entries = array_merge(
                array_map(static fn ($file) => $file->getFilename(), File::files($path)),
                array_map(static fn ($dir) => basename((string) $dir), File::directories($path)),
            );

            foreach ($entries as $name) {
                if (! preg_match('/^(useKinetix|kinetix)/i', $name)) {
                    $offenders[] = $directory.'/'.$name;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These published files could clobber a host file: '.implode(', ', $offenders),
        );
    }

    public function test_the_components_publish_ships_every_directory_its_imports_need(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        // Published components import `@/icons/kinetixBrands` and the docs tell
        // hosts to register `@/plugins/kinetix*`; omitting either from the publish
        // map broke the host's Vite build on an unresolvable import.
        $this->assertFileExists(resource_path('js/icons/kinetixBrands.ts'));
        $this->assertFileExists(resource_path('js/plugins/kinetixAccessibility.ts'));
        $this->assertFileExists(resource_path('js/composables/useKinetixCan.ts'));
        $this->assertFileExists(resource_path('js/stores/kinetixNotifications.ts'));
    }

    public function test_no_package_source_imports_an_unpublished_path(): void
    {
        // Anything a shipped component imports from `@/…` must be inside one of
        // the published directories, or the host build fails at that import.
        $published = ['components', 'composables', 'stores', 'plugins', 'icons', 'types'];
        $offenders = [];

        foreach (File::allFiles(__DIR__.'/../../resources/js') as $file) {
            if (! in_array($file->getExtension(), ['ts', 'vue'], true)) {
                continue;
            }

            preg_match_all('/from\s+[\'"]@\/([a-zA-Z0-9_-]+)/', (string) $file->getContents(), $matches);

            foreach ($matches[1] ?? [] as $segment) {
                if (! in_array($segment, $published, true)) {
                    $offenders[] = $file->getRelativePathname().' → @/'.$segment;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            'These imports point outside the publish map: '.implode(', ', array_unique($offenders)),
        );
    }

    public function test_every_package_source_imports_types_from_the_new_path(): void
    {
        $offenders = [];

        foreach (File::allFiles(__DIR__.'/../../resources/js') as $file) {
            if (! in_array($file->getExtension(), ['ts', 'vue'], true)) {
                continue;
            }

            $contents = (string) $file->getContents();

            if (preg_match('/from\s+[\'"]@\/types[\'"]/', $contents) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'These files still import from the host barrel: '.implode(', ', $offenders));
    }
}
