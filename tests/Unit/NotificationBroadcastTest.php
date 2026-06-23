<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    public function test_does_not_broadcast_by_default(): void
    {
        config()->set('kinetix.notifications.broadcast', false);
        config()->set('kinetix.broadcasting.echo', null);

        $this->assertFalse(Notification::shouldBroadcast());
    }

    public function test_broadcasts_when_the_dedicated_flag_is_on(): void
    {
        config()->set('kinetix.notifications.broadcast', true);
        config()->set('kinetix.broadcasting.echo', null);

        $this->assertTrue(Notification::shouldBroadcast());
    }

    public function test_broadcasts_when_the_echo_block_is_configured(): void
    {
        config()->set('kinetix.notifications.broadcast', false);
        config()->set('kinetix.broadcasting.echo', ['broadcaster' => 'reverb']);

        $this->assertTrue(Notification::shouldBroadcast());
    }
}
