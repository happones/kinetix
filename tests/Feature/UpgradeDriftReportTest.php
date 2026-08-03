<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\PublishedFiles;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * `kinetix:upgrade` runs from composer's `post-autoload-dump`, so it silently
 * discarded local edits to published files — the kind of change that "stops
 * existing" in CI with nobody having touched it. It now names them, comparing
 * against the manifest the LAST publish recorded — comparing against the new
 * sources would flag every upstream change as a "local edit" after a composer
 * update (the v0.119→v0.132 "113 files had local edits" noise).
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
        File::delete(PublishedFiles::manifestPath());

        parent::tearDown();
    }

    public function test_it_names_the_files_whose_local_edits_were_overwritten(): void
    {
        // Adopt the components publish with a recorded baseline, then drift
        // one file locally.
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();
        PublishedFiles::record();

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
        PublishedFiles::record();

        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('had local edits')
            ->assertSuccessful();
    }

    public function test_upstream_changes_are_not_reported_as_local_edits(): void
    {
        // Simulate an app sitting on a PREVIOUS Kinetix version: the file on
        // disk differs from the package's new sources, but matches what the
        // last publish wrote (the recorded baseline).
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        $file = resource_path('js/components/kinetix/KinetixToaster.vue');
        File::put($file, '<template><div>previous version content</div></template>');
        PublishedFiles::record();

        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('had local edits')
            ->assertSuccessful();

        // The upgrade still refreshed the file to the new version…
        $this->assertStringNotContainsString('previous version content', (string) File::get($file));
        // …and re-baselined it, so the next run is clean too.
        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('had local edits')
            ->assertSuccessful();
    }

    public function test_first_upgrade_without_a_baseline_claims_nothing_and_records_one(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'kinetix-components'])->assertSuccessful();

        // Even a real local edit can't be distinguished without a baseline —
        // stay quiet rather than crying wolf over every upstream change.
        File::put(
            resource_path('js/components/kinetix/KinetixToaster.vue'),
            '<template><div>local edit</div></template>',
        );

        $this->assertNull(PublishedFiles::recordedHashes());

        $this->artisan('kinetix:upgrade')
            ->doesntExpectOutputToContain('had local edits')
            ->expectsOutputToContain('baseline recorded')
            ->assertSuccessful();

        $this->assertNotNull(PublishedFiles::recordedHashes());
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
