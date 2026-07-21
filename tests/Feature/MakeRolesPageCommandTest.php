<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\File;

class MakeRolesPageCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('js/pages/Kinetix/Roles'));

        parent::tearDown();
    }

    public function test_scaffolds_the_roles_page_gated_by_the_manage_permission(): void
    {
        $this->artisan('kinetix:make-roles-page')->assertSuccessful();

        $pagePath = resource_path('js/pages/Kinetix/Roles/Index.vue');
        $this->assertFileExists($pagePath);

        $page = File::get($pagePath);

        // The page mounts the overview behind the built-in ability, inside the
        // standard starter-kit wrapper (min-w-0 so wide tables scroll locally).
        $this->assertStringContainsString('<KinetixCan permission="roles.manage">', $page);
        $this->assertStringContainsString('<KinetixRolesOverview />', $page);
        $this->assertStringContainsString('template #denied', $page);
        $this->assertStringContainsString('flex h-full min-w-0 flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4', $page);
    }

    public function test_refuses_to_overwrite_without_force(): void
    {
        $this->artisan('kinetix:make-roles-page')->assertSuccessful();

        File::put(resource_path('js/pages/Kinetix/Roles/Index.vue'), 'customized');

        $this->artisan('kinetix:make-roles-page')->assertFailed();
        $this->assertSame('customized', File::get(resource_path('js/pages/Kinetix/Roles/Index.vue')));

        $this->artisan('kinetix:make-roles-page', ['--force' => true])->assertSuccessful();
        $this->assertStringContainsString('KinetixRolesOverview', File::get(resource_path('js/pages/Kinetix/Roles/Index.vue')));
    }
}
