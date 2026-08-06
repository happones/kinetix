<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

class DpdPost extends Model
{
    protected $table = 'dpd_posts';
}

class DpdPostResource extends Resource
{
    protected static ?string $model = DpdPost::class;

    public static function permissionFeature(): ?string
    {
        return 'dpd-posts';
    }
}

/** The anti-pattern: synced permissions that this policy silently ignores. */
class DpdStaticTruePolicy
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function update(mixed $user, DpdPost $post): bool
    {
        // Static grant — the role matrix has no effect here.
        return true;
    }

    public function delete(mixed $user, DpdPost $post): bool
    {
        return $user->can('dpd-posts.delete');
    }
}

/** The documented pattern: every ability delegates to the matrix. */
class DpdDelegatingPolicy
{
    public function viewAny(mixed $user): bool
    {
        return $user->can('dpd-posts.viewAny');
    }

    public function update(mixed $user, DpdPost $post): bool
    {
        return $user->can('dpd-posts.update');
    }
}

class DoctorPolicyDelegationTest extends TestCase
{
    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.permissions.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        KinetixPermissions::resource(DpdPostResource::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function doctorFindings(): array
    {
        Artisan::call('kinetix:doctor', ['--json' => true]);

        $output = json_decode(Artisan::output(), true);

        return $output['findings'] ?? [];
    }

    private function permissionWarnings(): array
    {
        return array_values(array_filter(
            $this->doctorFindings(),
            fn (array $f) => $f['section'] === 'Permissions' && $f['level'] === 'warning',
        ));
    }

    public function test_a_synced_feature_with_a_static_true_policy_is_flagged(): void
    {
        Permission::create(['name' => 'dpd-posts.update', 'guard_name' => 'web']);
        Gate::policy(DpdPost::class, DpdStaticTruePolicy::class);

        $messages = array_column($this->permissionWarnings(), 'message');
        $hit      = array_values(array_filter($messages, fn ($m) => str_contains($m, 'static true')));

        $this->assertNotEmpty($hit, 'expected a static-true policy warning');
        // Only the static methods are named; the delegating `delete` is not.
        $this->assertStringContainsString('viewAny', $hit[0]);
        $this->assertStringContainsString('update', $hit[0]);
        $this->assertStringNotContainsString('delete', $hit[0]);
    }

    public function test_a_delegating_policy_is_not_flagged(): void
    {
        Permission::create(['name' => 'dpd-posts.update', 'guard_name' => 'web']);
        Gate::policy(DpdPost::class, DpdDelegatingPolicy::class);

        $messages = array_column($this->permissionWarnings(), 'message');

        $this->assertSame(
            [],
            array_values(array_filter($messages, fn ($m) => str_contains($m, 'static true'))),
        );
    }

    public function test_an_unsynced_feature_is_left_alone_but_a_missing_policy_is_flagged(): void
    {
        // No permissions rows: static-true stays quiet (nothing is enforced
        // yet), but a registered feature with NO policy at all is called out.
        $messages = array_column($this->permissionWarnings(), 'message');

        $this->assertNotEmpty(array_filter($messages, fn ($m) => str_contains($m, 'NO policy')));
        $this->assertSame(
            [],
            array_values(array_filter($messages, fn ($m) => str_contains($m, 'static true'))),
        );
    }
}
