<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * An invokable class-string satisfies `attach_member` — the config:cache-safe
 * form must not trip the warning.
 */
class AttachMemberAction
{
    public function __invoke(mixed $user, mixed $provision): void {}
}

class MembershipAttachConfiguredTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.teams', true);
        $app['config']->set('kinetix.membership.attach_member', AttachMemberAction::class);

        Log::spy();
    }

    public function test_no_warning_when_a_callback_is_configured(): void
    {
        Log::shouldNotHaveReceived('warning');

        $this->assertTrue(true);
    }
}
