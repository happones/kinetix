<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Permissions\Feature;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tests\TestCase;

class RegistryPostResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'posts';
    }
}

class PermissionRegistryTest extends TestCase
{
    public function test_feature_builds_namespaced_crud_keys(): void
    {
        $feature = (new Feature('posts'))->crud();

        $this->assertSame(
            ['posts.viewAny', 'posts.view', 'posts.create', 'posts.update', 'posts.delete'],
            $feature->permissionKeys(),
        );
    }

    public function test_feature_supports_custom_abilities_and_labels(): void
    {
        $feature = (new Feature('billing'))->label('Billing')->ability('manage', 'Manage billing');

        $this->assertSame('Billing', $feature->getLabel());
        $this->assertSame(['billing.manage'], $feature->permissionKeys());
        $this->assertSame(['manage' => 'Manage billing'], $feature->getAbilities());
    }

    public function test_feature_label_defaults_to_humanized_name(): void
    {
        $this->assertSame('Blog Posts', (new Feature('blog_posts'))->getLabel());
    }

    public function test_registry_merges_explicit_and_resource_derived_features(): void
    {
        $registry = new PermissionRegistry;
        $registry->feature('billing')->ability('manage');
        $registry->resource(RegistryPostResource::class);

        $all = $registry->allPermissions();

        $this->assertContains('billing.manage', $all);
        $this->assertContains('posts.create', $all);
        $this->assertContains('posts.delete', $all);
        // 1 billing + 5 posts CRUD
        $this->assertCount(6, $all);
    }

    public function test_resource_without_permission_feature_contributes_nothing(): void
    {
        $registry = new PermissionRegistry;
        $registry->resource(Resource::class);

        $this->assertSame([], $registry->allPermissions());
    }
}
