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

    public function test_feature_of_names_the_declaring_feature(): void
    {
        $registry = new PermissionRegistry;
        $registry->feature('billing')->ability('manage');
        $registry->resource(RegistryPostResource::class);

        $this->assertSame('billing', $registry->featureOf('billing.manage'));
        $this->assertSame('posts', $registry->featureOf('posts.update'));
        $this->assertNull($registry->featureOf('posts.publish'));
        $this->assertNull($registry->featureOf('update'));
    }

    public function test_platform_keys_follow_whole_feature_and_named_marks(): void
    {
        $registry = new PermissionRegistry;
        $registry->feature('users')->ability('impersonate')->ability('export')->platform('impersonate');
        $registry->feature('tenants')->crud()->platform();

        $this->assertTrue($registry->isPlatform('users.impersonate'));
        $this->assertFalse($registry->isPlatform('users.export'));
        $this->assertTrue($registry->isPlatform('tenants.delete'));
        $this->assertFalse($registry->isPlatform('tenants.publish'));
    }

    public function test_the_lookup_is_rebuilt_after_a_feature_is_added_post_has(): void
    {
        $registry = new PermissionRegistry;
        $registry->feature('billing')->ability('manage');

        $this->assertTrue($registry->has('billing.manage'));
        $this->assertFalse($registry->has('users.impersonate'));
        $this->assertFalse($registry->isPlatform('users.impersonate'));

        $registry->feature('users')->ability('impersonate')->platform('impersonate');

        $this->assertTrue($registry->has('users.impersonate'));
        $this->assertSame('users', $registry->featureOf('users.impersonate'));
        $this->assertTrue($registry->isPlatform('users.impersonate'));
    }

    public function test_flush_lookup_picks_up_a_platform_mark_on_a_held_feature(): void
    {
        $registry = new PermissionRegistry;
        $feature  = $registry->feature('users')->ability('impersonate');

        $this->assertFalse($registry->isPlatform('users.impersonate'));

        // A feature object held past the first lookup changes behind the
        // registry's back; flushLookup() is the documented way to resync.
        $feature->platform('impersonate');
        $registry->flushLookup();

        $this->assertTrue($registry->isPlatform('users.impersonate'));
    }
}
