<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Credentials\KinetixPasswords;
use Happones\Kinetix\Credentials\PasswordHistory;
use Happones\Kinetix\Credentials\PasswordObserver;
use Happones\Kinetix\Credentials\PasswordPolicy;
use Happones\Kinetix\Credentials\Rules\NotAPreviousPassword;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;

class PwUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_changed_at'  => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }
}

/**
 * The password lifecycle: expiry, history and forced change.
 *
 * The design bet is that the bookkeeping hangs off the user model's own events
 * rather than a call site, so the policy holds whatever changes a password —
 * Fortify, a reset link, a seeder, tinker. Most of what follows is proving that
 * bet in each of those shapes.
 */
class PasswordPolicyTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.credentials.enabled', true);
        $app['config']->set('kinetix.credentials.user_model', PwUser::class);
        $app['config']->set('kinetix.membership.user_model', PwUser::class);
        // The base TestCase strips middleware; these routes need a session for
        // validation errors and flashed toasts.
        $app['config']->set('kinetix.middleware', ['web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        Schema::create('kinetix_password_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('password');
            $table->timestamp('created_at')->nullable();
        });

        // The service provider already registered the observer on this model
        // (`credentials.user_model`); registering it again here would record
        // every change twice.
        PasswordObserver::flush();

        // Inertia renders through a root Blade view the package has no reason
        // to ship; a stub is enough to exercise the controller end to end.
        $this->stubRootView();
    }

    private function stubRootView(): void
    {
        $dir = sys_get_temp_dir().'/kinetix-pw-views';

        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($dir.'/app.blade.php', '<html><body>@inertia</body></html>');

        View::addLocation($dir);
    }

    protected function tearDown(): void
    {
        PasswordObserver::flush();

        parent::tearDown();
    }

    private function user(string $password = 'secret-one'): PwUser
    {
        return PwUser::create(['name' => 'Jane', 'password' => Hash::make($password)]);
    }

    // -- Everything off by default -------------------------------------------

    public function test_the_policy_is_inert_until_a_rule_is_configured(): void
    {
        $user = $this->user();

        // Module enabled, no rules set: nothing expires, nothing is forbidden.
        $this->assertNull(KinetixPasswords::expiresAt($user));
        $this->assertFalse(KinetixPasswords::isExpired($user));
        $this->assertFalse(KinetixPasswords::requiresChange($user));
        $this->assertFalse(KinetixPasswords::wasUsedBefore($user, 'secret-one'));
    }

    public function test_nothing_happens_at_all_when_the_module_is_disabled(): void
    {
        config()->set('kinetix.credentials.enabled', false);
        config()->set('kinetix.credentials.passwords.expires_after_days', 1);

        $user = $this->user();
        $user->forceFill(['password_changed_at' => now()->subYear()])->saveQuietly();

        $this->assertFalse(KinetixPasswords::isExpired($user));
    }

    // -- Expiry ---------------------------------------------------------------

    public function test_a_password_expires_after_the_configured_days(): void
    {
        config()->set('kinetix.credentials.passwords.expires_after_days', 90);

        $user = $this->user();

        // The observer stamped the change on create.
        $this->assertNotNull($user->fresh()->password_changed_at);
        $this->assertFalse(KinetixPasswords::isExpired($user->fresh()));

        $user->forceFill(['password_changed_at' => now()->subDays(91)])->saveQuietly();

        $this->assertTrue(KinetixPasswords::isExpired($user->fresh()));
        $this->assertTrue(KinetixPasswords::requiresChange($user->fresh()));
    }

    public function test_an_account_that_predates_the_policy_is_not_locked_out(): void
    {
        config()->set('kinetix.credentials.passwords.expires_after_days', 30);

        $user = $this->user();
        // No stamp = the account existed before the policy was switched on.
        $user->forceFill(['password_changed_at' => null])->saveQuietly();

        // Treating null as "expired" would lock out every existing account the
        // moment an admin enables the policy — the worst possible first
        // impression of the feature.
        $this->assertFalse(KinetixPasswords::isExpired($user->fresh()));
        $this->assertNull(KinetixPasswords::expiresAt($user->fresh()));
    }

    public function test_days_until_expiry_and_the_warning_window(): void
    {
        config()->set('kinetix.credentials.passwords.expires_after_days', 30);
        config()->set('kinetix.credentials.passwords.warn_before_days', 7);

        $user = $this->user();

        $user->forceFill(['password_changed_at' => now()->subDays(28)])->saveQuietly();
        $this->assertSame(2, KinetixPasswords::daysUntilExpiry($user->fresh()));
        $this->assertTrue(app(PasswordPolicy::class)->isExpiring($user->fresh()));

        $user->forceFill(['password_changed_at' => now()->subDays(1)])->saveQuietly();
        $this->assertFalse(app(PasswordPolicy::class)->isExpiring($user->fresh()));
    }

    // -- History --------------------------------------------------------------

    public function test_a_recent_password_cannot_be_reused(): void
    {
        config()->set('kinetix.credentials.passwords.history', 3);

        $user = $this->user('first-one');

        // The current password counts as used — even before any change, which
        // is the case a naive history table gets wrong.
        $this->assertTrue(KinetixPasswords::wasUsedBefore($user, 'first-one'));

        $user->forceFill(['password' => Hash::make('second-one')])->save();
        $user->forceFill(['password' => Hash::make('third-one')])->save();

        foreach (['first-one', 'second-one', 'third-one'] as $used) {
            $this->assertTrue(KinetixPasswords::wasUsedBefore($user->fresh(), $used), "{$used} should be remembered");
        }

        $this->assertFalse(KinetixPasswords::wasUsedBefore($user->fresh(), 'never-used'));
    }

    public function test_history_is_pruned_to_the_configured_depth(): void
    {
        config()->set('kinetix.credentials.passwords.history', 2);

        $user = $this->user('one');

        foreach (['two', 'three', 'four', 'five'] as $next) {
            $user->forceFill(['password' => Hash::make($next)])->save();
        }

        $this->assertSame(2, PasswordHistory::query()->where('user_id', $user->getKey())->count());

        // The oldest is forgotten, so it becomes reusable — that is what a
        // bounded history means.
        $this->assertFalse(KinetixPasswords::wasUsedBefore($user->fresh(), 'one'));
        $this->assertTrue(KinetixPasswords::wasUsedBefore($user->fresh(), 'five'));
    }

    public function test_history_is_capped_so_a_change_cannot_take_seconds(): void
    {
        config()->set('kinetix.credentials.passwords.history', 500);

        // Each comparison is a deliberately slow hash check, so the depth is
        // capped no matter what the config says.
        $this->assertSame(5, app(PasswordPolicy::class)->historyDepth());
    }

    public function test_no_history_is_kept_when_the_rule_is_off(): void
    {
        config()->set('kinetix.credentials.passwords.history', 0);

        $user = $this->user('one');
        $user->forceFill(['password' => Hash::make('two')])->save();

        $this->assertSame(0, PasswordHistory::query()->count());
        $this->assertFalse(KinetixPasswords::wasUsedBefore($user->fresh(), 'one'));
    }

    public function test_the_validation_rule_rejects_a_reused_password(): void
    {
        config()->set('kinetix.credentials.passwords.history', 3);

        $user = $this->user('first-one');

        $validator = Validator::make(
            ['password' => 'first-one'],
            ['password' => [new NotAPreviousPassword($user)]],
        );

        $this->assertTrue($validator->fails());
        // Kinetix translations live in the HOST's published `lang/*/kinetix.php`,
        // so inside the package `__()` hands back the key — asserting the
        // wording here would be asserting the test environment, not the rule.
        $this->assertStringContainsString(
            'password_previously_used',
            $validator->errors()->first('password'),
        );

        $ok = Validator::make(
            ['password' => 'brand-new'],
            ['password' => [new NotAPreviousPassword($user)]],
        );

        $this->assertFalse($ok->fails());
    }

    // -- Forced change & temporary credentials --------------------------------

    public function test_forcing_a_change_requires_one(): void
    {
        $user = $this->user();

        KinetixPasswords::forceChange($user);

        $this->assertTrue(KinetixPasswords::mustChange($user->fresh()));
        $this->assertTrue(KinetixPasswords::requiresChange($user->fresh()));
    }

    public function test_a_temporary_password_is_returned_once_and_flags_a_change(): void
    {
        $user = $this->user();

        $plain = KinetixPasswords::issueTemporary($user);

        $this->assertNotSame('', $plain);
        $this->assertTrue(Hash::check($plain, $user->fresh()->password));
        // Issuing changes the password AND flags it in one save — the observer
        // must not clear the flag it just saw set.
        $this->assertTrue(KinetixPasswords::mustChange($user->fresh()));
    }

    public function test_changing_the_password_clears_the_forced_change(): void
    {
        $user = $this->user();
        KinetixPasswords::issueTemporary($user);

        $user->forceFill(['password' => Hash::make('chosen-by-me')])->save();

        $this->assertFalse(KinetixPasswords::mustChange($user->fresh()));
        $this->assertFalse(KinetixPasswords::requiresChange($user->fresh()));
    }

    public function test_an_unused_temporary_credential_can_be_told_to_have_expired(): void
    {
        config()->set('kinetix.credentials.passwords.temporary_ttl_hours', 24);

        $user = $this->user();
        KinetixPasswords::issueTemporary($user);

        $this->assertFalse(KinetixPasswords::temporaryHasExpired($user->fresh()));

        $user->forceFill(['password_changed_at' => now()->subHours(25)])->saveQuietly();

        $this->assertTrue(KinetixPasswords::temporaryHasExpired($user->fresh()));
    }

    // -- The observer holds on every path -------------------------------------

    public function test_a_password_set_outside_kinetix_is_still_recorded(): void
    {
        config()->set('kinetix.credentials.passwords.history', 3);
        config()->set('kinetix.credentials.passwords.expires_after_days', 30);

        $user = $this->user('one');
        $user->forceFill(['password_changed_at' => now()->subDays(60)])->saveQuietly();
        $this->assertTrue(KinetixPasswords::isExpired($user->fresh()));

        // Exactly what Fortify's UpdateUserPassword does — no Kinetix call.
        $user->forceFill(['password' => Hash::make('two')])->save();

        $this->assertFalse(KinetixPasswords::isExpired($user->fresh()));
        $this->assertTrue(KinetixPasswords::wasUsedBefore($user->fresh(), 'one'));
    }

    public function test_saving_a_user_without_touching_the_password_records_nothing(): void
    {
        config()->set('kinetix.credentials.passwords.history', 3);

        $user  = $this->user();
        $count = PasswordHistory::query()->count();

        $user->forceFill(['name' => 'Renamed'])->save();

        $this->assertSame($count, PasswordHistory::query()->count());
    }

    public function test_deleting_a_user_forgets_their_history(): void
    {
        config()->set('kinetix.credentials.passwords.history', 3);

        $user = $this->user('one');
        $user->forceFill(['password' => Hash::make('two')])->save();

        $this->assertGreaterThan(0, PasswordHistory::query()->where('user_id', $user->getKey())->count());

        $user->delete();

        $this->assertSame(0, PasswordHistory::query()->where('user_id', $user->getKey())->count());
    }

    public function test_the_observer_stands_down_when_the_columns_are_missing(): void
    {
        // The module can be enabled before its migration runs; every user save
        // would otherwise fail on a column that isn't there.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'must_change_password']);
        });
        PasswordObserver::flush();

        $user = PwUser::create(['name' => 'Jane', 'password' => Hash::make('one')]);

        $this->assertNotNull($user->fresh());
    }

    // -- Middleware -----------------------------------------------------------

    private function protectedRoute(): void
    {
        Route::middleware(['web', 'kinetix.password'])
            ->get('/pw-app', fn () => response()->json(['ok' => true]))
            ->name('app.home');
    }

    public function test_the_middleware_lets_a_current_password_through(): void
    {
        $this->protectedRoute();

        $this->actingAs($this->user())->getJson('/pw-app')->assertOk();
    }

    public function test_the_middleware_redirects_a_forced_change(): void
    {
        $this->protectedRoute();

        $user = $this->user();
        KinetixPasswords::forceChange($user);

        $this->actingAs($user->fresh())->get('/pw-app')
            ->assertRedirect(route('kinetix.password.change.show'))
            ->assertSessionHas('kinetix_toast');
    }

    public function test_the_middleware_answers_json_with_423_instead_of_a_redirect(): void
    {
        $this->protectedRoute();

        $user = $this->user();
        KinetixPasswords::forceChange($user);

        // An XHR must not receive an HTML page it can't interpret.
        $this->actingAs($user->fresh())->getJson('/pw-app')->assertStatus(423);
    }

    public function test_the_change_screen_itself_is_never_redirected_to_itself(): void
    {
        // The classic way this feature ships broken: everything redirects to
        // one screen, including that screen.
        $user = $this->user();
        KinetixPasswords::forceChange($user);

        $this->actingAs($user->fresh())
            ->get(route('kinetix.password.change.show'))
            ->assertOk();
    }

    public function test_extra_routes_can_be_exempted(): void
    {
        Route::middleware(['web', 'kinetix.password'])
            ->get('/pw-legal', fn () => 'terms')
            ->name('legal.terms');

        $user = $this->user();
        KinetixPasswords::forceChange($user);

        $this->actingAs($user->fresh())->get('/pw-legal')->assertRedirect();

        config()->set('kinetix.credentials.passwords.except', ['legal.*']);

        $this->actingAs($user->fresh())->get('/pw-legal')->assertOk();
    }

    // -- The change endpoint --------------------------------------------------

    public function test_a_user_changes_their_password_with_the_current_one(): void
    {
        $user = $this->user('old-secret-1');

        $this->actingAs($user)
            ->post(route('kinetix.password.change'), [
                'current_password'      => 'old-secret-1',
                'password'              => 'BrandNewPass!9',
                'password_confirmation' => 'BrandNewPass!9',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('BrandNewPass!9', $user->fresh()->password));
    }

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $user = $this->user('old-secret-1');

        $this->actingAs($user)
            ->post(route('kinetix.password.change'), [
                'current_password'      => 'not-it',
                'password'              => 'BrandNewPass!9',
                'password_confirmation' => 'BrandNewPass!9',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_a_temporary_credential_does_not_ask_for_the_current_password(): void
    {
        $user = $this->user();
        KinetixPasswords::issueTemporary($user);

        // The admin chose that password, not the user — asking them to repeat
        // it back adds a step without adding security.
        $this->actingAs($user->fresh())
            ->post(route('kinetix.password.change'), [
                'password'              => 'BrandNewPass!9',
                'password_confirmation' => 'BrandNewPass!9',
            ])
            ->assertRedirect();

        $this->assertFalse(KinetixPasswords::mustChange($user->fresh()));
    }

    public function test_the_endpoint_enforces_the_history_rule(): void
    {
        config()->set('kinetix.credentials.passwords.history', 3);

        $user = $this->user('old-secret-1');

        $this->actingAs($user)
            ->post(route('kinetix.password.change'), [
                'current_password'      => 'old-secret-1',
                'password'              => 'old-secret-1',
                'password_confirmation' => 'old-secret-1',
            ])
            ->assertSessionHasErrors('password');
    }

    // -- The shared prop ------------------------------------------------------

    public function test_the_shared_prop_carries_the_policy_state(): void
    {
        config()->set('kinetix.credentials.passwords.expires_after_days', 30);
        config()->set('kinetix.credentials.passwords.warn_before_days', 7);

        $user = $this->user();
        $user->forceFill(['password_changed_at' => now()->subDays(28)])->saveQuietly();
        $this->actingAs($user->fresh());

        /** @var callable $share */
        $share = Inertia::getShared('kinetix_credentials');
        $data  = value($share);

        $this->assertTrue($data['enabled']);
        $this->assertTrue($data['expiring']);
        $this->assertFalse($data['expired']);
        $this->assertSame(2, $data['daysUntilExpiry']);
        $this->assertNotNull($data['changeUrl']);
    }

    public function test_the_shared_prop_is_inert_for_a_guest(): void
    {
        /** @var callable $share */
        $share = Inertia::getShared('kinetix_credentials');
        $data  = value($share);

        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['requiresChange']);
    }
}
