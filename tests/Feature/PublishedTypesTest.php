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
