<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tests\TestCase;

class KinetixTeamsTest extends TestCase
{
    public function test_a_module_inherits_the_global_flag_when_unset(): void
    {
        config()->set('kinetix.onboarding.teams', null);

        config()->set('kinetix.teams', true);
        $this->assertTrue(KinetixTeams::enabledFor('onboarding'));

        config()->set('kinetix.teams', false);
        $this->assertFalse(KinetixTeams::enabledFor('onboarding'));
    }

    public function test_an_explicit_module_flag_overrides_the_global(): void
    {
        // Team-scoped app, personal billing.
        config()->set('kinetix.teams', true);
        config()->set('kinetix.billing.teams', false);
        $this->assertFalse(KinetixTeams::enabledFor('billing'));

        // User-scoped app, team-scoped settings only.
        config()->set('kinetix.teams', false);
        config()->set('kinetix.settings.teams', true);
        $this->assertTrue(KinetixTeams::enabledFor('settings'));
    }

    public function test_an_unknown_module_falls_back_to_the_global_flag(): void
    {
        config()->set('kinetix.teams', true);

        $this->assertTrue(KinetixTeams::enabledFor('something-new'));
    }
}
