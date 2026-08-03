<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Mail\KinetixMail;
use Happones\Kinetix\Mail\MailTemplate;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Mail templates went from one global pool — where any team's `viewKinetixMail`
 * holder edited every tenant's templates — to the hybrid shape roles use:
 * `team_id` NULL is a platform default, a team may override it under the same
 * key, and the resolver prefers the override.
 */
class MailTemplateTeamScopingTest extends TestCase
{
    use ResolvesTeamRoutes;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Teams put the permission-team middleware on the routes.
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.mail_templates.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/2026_01_01_000016_create_kinetix_mail_templates_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000024_add_team_id_to_kinetix_mail_templates_table.php')->up();

        Gate::define('viewKinetixMail', fn (mixed $user = null): bool => true);

        $this->withTeamSegment(7);
    }

    /**
     * The module's endpoints live under the team segment like every other one.
     */
    private function endpoint(int|string|null $id = null): string
    {
        $base = config('kinetix.teams') ? '/7/_kinetix/mail-templates' : '/_kinetix/mail-templates';

        return $id === null ? $base : "{$base}/{$id}";
    }

    private function template(?int $teamId, string $key, string $subject): MailTemplate
    {
        return MailTemplate::create([
            'team_id' => $teamId,
            'key'     => $key,
            'name'    => ucfirst($key),
            'subject' => $subject,
            'body'    => 'Hello',
            'format'  => 'markdown',
            'enabled' => true,
        ]);
    }

    public function test_the_migration_moves_uniqueness_from_key_to_team_plus_key(): void
    {
        // The same key in two tenants — impossible under the original unique index.
        $this->template(null, 'welcome', 'Global');
        $this->template(7, 'welcome', 'Team 7');
        $this->template(8, 'welcome', 'Team 8');

        $this->assertSame(3, MailTemplate::query()->where('key', 'welcome')->count());
    }

    public function test_a_teams_override_wins_over_the_global_default(): void
    {
        $this->template(null, 'welcome', 'Global');
        $this->template(7, 'welcome', 'Team 7');

        $this->assertSame('Team 7', KinetixMail::resolve('welcome')?->subject);
    }

    public function test_the_global_default_is_used_without_an_override(): void
    {
        $this->template(null, 'welcome', 'Global');

        $this->assertSame('Global', KinetixMail::resolve('welcome')?->subject);
    }

    public function test_another_teams_override_is_never_resolved(): void
    {
        $this->template(8, 'welcome', 'Team 8');

        $this->assertNull(KinetixMail::resolve('welcome'));
    }

    public function test_a_queued_job_without_team_context_gets_the_global_default(): void
    {
        $this->template(null, 'welcome', 'Global');
        $this->template(7, 'welcome', 'Team 7');

        $this->withoutTeamSegment();

        $this->assertSame('Global', KinetixMail::resolve('welcome')?->subject);
        // …unless the job passes the tenant explicitly.
        $this->assertSame('Team 7', KinetixMail::resolve('welcome', teamId: 7)?->subject);
    }

    public function test_a_disabled_override_turns_the_mail_off_for_that_team_only(): void
    {
        $this->template(null, 'welcome', 'Global');
        $this->template(7, 'welcome', 'Team 7')->update(['enabled' => false]);

        $this->assertNull(KinetixMail::template('welcome'));

        $this->withTeamSegment(8);
        $this->assertSame('Global', KinetixMail::template('welcome')?->subject);
    }

    public function test_the_listing_shows_the_override_instead_of_the_default_it_replaces(): void
    {
        $this->template(null, 'welcome', 'Global');
        $this->template(null, 'invoice', 'Global invoice');
        $this->template(7, 'welcome', 'Team 7');
        $this->template(8, 'welcome', 'Team 8');

        $response = $this->getJson($this->endpoint())->assertOk();

        $subjects = array_column($response->json('templates'), 'subject', 'key');

        $this->assertSame(['invoice' => 'Global invoice', 'welcome' => 'Team 7'], $subjects);
    }

    public function test_editing_a_global_template_from_a_team_forks_it(): void
    {
        $global = $this->template(null, 'welcome', 'Global');

        $this->putJson($this->endpoint($global->id), [
            'key'     => 'welcome',
            'name'    => 'Welcome',
            'subject' => 'Ours',
            'body'    => 'Hi',
            'format'  => 'markdown',
        ])->assertCreated()->assertJsonPath('forked', true);

        // The platform default is untouched; the team gets its own row.
        $this->assertSame('Global', $global->fresh()?->subject);
        $this->assertSame('Ours', MailTemplate::query()->where('team_id', 7)->first()?->subject);
    }

    public function test_a_teams_own_template_is_edited_in_place(): void
    {
        $own = $this->template(7, 'welcome', 'Mine');

        $this->putJson($this->endpoint($own->id), [
            'key'     => 'welcome',
            'name'    => 'Welcome',
            'subject' => 'Updated',
            'body'    => 'Hi',
            'format'  => 'markdown',
        ])->assertOk();

        $this->assertSame('Updated', $own->fresh()?->subject);
        $this->assertSame(1, MailTemplate::query()->count());
    }

    public function test_another_teams_template_is_a_404(): void
    {
        $foreign = $this->template(8, 'welcome', 'Team 8');

        $this->deleteJson($this->endpoint($foreign->id))->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_a_team_cannot_delete_the_global_default(): void
    {
        $global = $this->template(null, 'welcome', 'Global');

        $this->deleteJson($this->endpoint($global->id))->assertForbidden();
        $this->assertModelExists($global);
    }

    public function test_deleting_an_override_reverts_the_team_to_the_default(): void
    {
        $this->template(null, 'welcome', 'Global');
        $override = $this->template(7, 'welcome', 'Team 7');

        $this->deleteJson($this->endpoint($override->id))->assertOk();

        $this->assertSame('Global', KinetixMail::resolve('welcome')?->subject);
    }

    public function test_new_templates_belong_to_the_active_team(): void
    {
        $this->postJson($this->endpoint(), [
            'key'     => 'receipt',
            'name'    => 'Receipt',
            'subject' => 'Your receipt',
            'body'    => 'Thanks',
            'format'  => 'markdown',
        ])->assertCreated();

        $this->assertSame(7, (int) MailTemplate::query()->where('key', 'receipt')->first()?->team_id);
    }
}
