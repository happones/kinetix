<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Tests\Feature\Fixtures\ResourcesFixture\FixtureEmployeeResource;
use Happones\Kinetix\Tests\TestCase;

class PermissionResourceDiscoveryTest extends TestCase
{
    protected string $fixturesPath = __DIR__.'/Fixtures/ResourcesFixture';

    protected string $fixturesNamespace = 'Happones\\Kinetix\\Tests\\Feature\\Fixtures\\ResourcesFixture';

    public function test_discovers_resource_crud_permissions_from_a_directory(): void
    {
        $registry = new PermissionRegistry;
        $registry->discoverResources($this->fixturesPath, $this->fixturesNamespace);

        $permissions = $registry->allPermissions();

        $this->assertContains('employees.viewAny', $permissions);
        $this->assertContains('employees.create', $permissions);
        $this->assertContains('departments.update', $permissions);
        $this->assertContains('departments.delete', $permissions);
    }

    public function test_resources_without_a_permission_feature_are_skipped(): void
    {
        $registry = new PermissionRegistry;
        $registry->discoverResources($this->fixturesPath, $this->fixturesNamespace);

        // The opt-out resource and the abstract one contribute no feature keys.
        foreach ($registry->allPermissions() as $key) {
            $this->assertStringStartsNotWith('abstract-should-not-appear', $key);
        }

        $this->assertArrayNotHasKey('abstract-should-not-appear', $registry->features());
    }

    public function test_manual_registration_merges_with_discovery_without_duplicates(): void
    {
        $registry = new PermissionRegistry;
        $registry->discoverResources($this->fixturesPath, $this->fixturesNamespace);
        // Manually register a class already covered by discovery.
        $registry->resource(FixtureEmployeeResource::class);

        $permissions = $registry->allPermissions();

        $this->assertSame(count($permissions), count(array_unique($permissions)));
        $this->assertContains('employees.viewAny', $permissions);
    }

    public function test_repeat_calls_are_stable(): void
    {
        $registry = new PermissionRegistry;
        $registry->discoverResources($this->fixturesPath, $this->fixturesNamespace);

        $first  = $registry->allPermissions();
        $second = $registry->allPermissions();

        $this->assertSame($first, $second);
    }

    public function test_discovery_on_a_missing_directory_is_a_noop(): void
    {
        $registry = new PermissionRegistry;
        $registry->discoverResources('/path/does/not/exist', 'Some\\Namespace');

        $this->assertSame([], $registry->allPermissions());
    }

    public function test_features_include_both_discovered_resources(): void
    {
        $registry = new PermissionRegistry;
        $registry->discoverResources($this->fixturesPath, $this->fixturesNamespace);

        $features = $registry->features();

        $this->assertArrayHasKey('employees', $features);
        $this->assertArrayHasKey('departments', $features);
    }
}
