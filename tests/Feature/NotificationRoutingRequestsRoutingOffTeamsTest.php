<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;
use Happones\Kinetix\Tests\Fakes\RoutedAlert;
use Happones\Kinetix\Tests\Fakes\RoutingCallbacks;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The app has teams but routing is app-wide (`notification_routing.teams`
 * off): rules are stored without a team, yet the host callbacks still get the
 * REAL team key, on the screen and when sending alike.
 */
class NotificationRoutingRequestsRoutingOffTeamsTest extends NotificationRoutingRequestsTest
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.notification_routing.teams', false);
    }

    protected function ruleScope(): int|string|null
    {
        return null;
    }

    public function test_the_callbacks_get_the_real_team_key_on_the_screen_and_when_sending(): void
    {
        NotificationFacade::fake();

        $fallback = $this->member($this->alpha, 'Fallback');
        KinetixNotificationRouting::type('inventory.low-stock')
            ->availableWhen([RoutingCallbacks::class, 'available'])
            ->fallbackUsing([RoutingCallbacks::class, 'fallback']);
        RoutingCallbacks::$fallbackUsers = [$fallback];

        $this->assertContains('inventory.low-stock', $this->types());
        $this->routing()->notifyNow(new RoutedAlert);

        $this->assertSame([$this->alpha->getKey()], array_values(array_unique(array_column(RoutingCallbacks::$availabilityCalls, 0))));
        $this->assertSame($this->alpha->getKey(), RoutingCallbacks::$fallbackCalls[0][0]);
        NotificationFacade::assertSentTo($fallback, RoutedAlert::class);
    }
}
