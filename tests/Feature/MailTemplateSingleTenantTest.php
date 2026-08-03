<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Mail\KinetixMail;
use Happones\Kinetix\Mail\MailTemplate;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;

/**
 * The tenant column is additive: with teams off, mail templates behave exactly
 * as before — one pool, `team_id` never written, nothing hidden from the UI.
 */
class MailTemplateSingleTenantTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.teams', false);
        $app['config']->set('kinetix.mail_templates.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/2026_01_01_000016_create_kinetix_mail_templates_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000024_add_team_id_to_kinetix_mail_templates_table.php')->up();

        Gate::define('viewKinetixMail', fn (mixed $user = null): bool => true);

        MailTemplate::create([
            'key'     => 'welcome',
            'name'    => 'Welcome',
            'subject' => 'Global',
            'body'    => 'Hello',
            'format'  => 'markdown',
            'enabled' => true,
        ]);
    }

    public function test_templates_are_listed_and_resolved_without_a_team(): void
    {
        $this->assertNull(MailTemplate::currentTeamId());
        $this->assertSame('Global', KinetixMail::resolve('welcome')?->subject);

        $this->getJson('/_kinetix/mail-templates')
            ->assertOk()
            ->assertJsonCount(1, 'templates');
    }

    public function test_a_template_is_still_editable_and_deletable(): void
    {
        $template = MailTemplate::query()->firstOrFail();

        $this->putJson("/_kinetix/mail-templates/{$template->id}", [
            'key'     => 'welcome',
            'name'    => 'Welcome',
            'subject' => 'Edited in place',
            'body'    => 'Hello',
            'format'  => 'markdown',
        ])->assertOk();

        $this->assertSame('Edited in place', $template->fresh()?->subject);

        $this->deleteJson("/_kinetix/mail-templates/{$template->id}")->assertOk();
        $this->assertModelMissing($template);
    }
}
