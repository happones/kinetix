<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

class MembershipAttachWarningTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The misconfiguration under test: team-scoped membership with no way to
        // link an activated member to the host's team pivot.
        $app['config']->set('kinetix.membership.enabled', true);
        $app['config']->set('kinetix.membership.teams', true);
        $app['config']->set('kinetix.membership.attach_member', null);

        Log::spy();
    }

    public function test_a_warning_is_logged_when_attach_member_is_missing(): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'attach_member'))
            ->once();
    }
}
