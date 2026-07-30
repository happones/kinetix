<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * `kinetix:upgrade` runs from composer's `post-autoload-dump`, so it silently
 * discarded local edits to published files — the kind of change that "stops
 * existing" in CI with nobody having touched it. It now names them.
 */
class UpgradeDriftReportTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('js/components/kinetix'));
        File::deleteDirectory(resource_path('js/composables'));
        File::deleteDirectory(resource_path('js/stores'));
        File::deleteDirectory(resource_path('js/types'));
        File::delete(resource_path('js/vue-i18n-locales.generated.js'));
        File::delete(resource_path('js/vue-i18n-locales.generated.ts'));

        parent::tearDown();
    }

    public function test_it_names_the_files_whose_local_edits_were_overwritten(): void
    {
        // Adopt the components publish, then drift one file locally.
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        $edited = resource_path('js/components/kinetix/KinetixToaster.vue');
        File::put($edited, '<template><div>local edit</div></template>');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('1 published file(s) had local edits and were overwritten')
            ->expectsOutputToContain('resources/js/components/kinetix/KinetixToaster.vue')
            ->assertSuccessful();

        // …and the local edit is indeed gone (the report is about transparency,
        // not about preserving it).
        $this->assertStringNotContainsString('local edit', (string) File::get($edited));
    }

    public function test_untouched_publishes_produce_no_drift_report(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('had local edits')
            ->assertSuccessful();
    }

    public function test_it_warns_when_both_i18n_bundles_exist(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        File::put(resource_path('js/vue-i18n-locales.generated.js'), 'export default {}');
        File::put(resource_path('js/vue-i18n-locales.generated.ts'), 'export default {}');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('Two Vue i18n bundles are present')
            ->expectsOutputToContain('Vite resolves .js before .ts')
            ->assertSuccessful();
    }

    public function test_it_explains_the_migration_for_a_legacy_types_barrel(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        // What an app upgrading from <= 0.118 has on disk.
        File::put(resource_path('js/types/index.ts'), 'export interface KinetixAction { name: string }');

        $this->artisan('kinetix:upgrade')
            ->expectsOutputToContain('no longer managed by Kinetix')
            ->expectsOutputToContain('types/kinetix.ts')
            ->assertSuccessful();
    }
}
