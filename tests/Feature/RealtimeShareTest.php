<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `kinetix_config.realtime` tells the bell whether to load Echo at all, and
 * `kinetix_config.spotlight.enabled` whether the palette's layout host loads
 * its body. Both keep optional code out of the browser on hosts that don't
 * use the feature.
 */
class RealtimeShareTest extends TestCase
{
    public function test_realtime_is_off_without_any_broadcasting_source(): void
    {
        config()->set('kinetix.notifications.broadcast', false);
        config()->set('kinetix.broadcasting.echo', null);
        config()->set('broadcasting.default', 'null');

        $this->assertFalse($this->sharedConfig()['realtime']);
    }

    public function test_realtime_follows_the_notifications_broadcast_switch(): void
    {
        config()->set('kinetix.notifications.broadcast', true);
        config()->set('kinetix.broadcasting.echo', null);
        config()->set('broadcasting.default', 'null');

        $this->assertTrue($this->sharedConfig()['realtime']);
    }

    public function test_realtime_follows_the_echo_block(): void
    {
        config()->set('kinetix.notifications.broadcast', false);
        config()->set('kinetix.broadcasting.echo', ['broadcaster' => 'reverb', 'key' => 'app-key']);
        config()->set('broadcasting.default', 'null');

        $this->assertTrue($this->sharedConfig()['realtime']);
    }

    /**
     * A driver that delivers to a socket server turns it on; the framework's
     * inert ones (`null`, `log`, or none at all) leave it off.
     */
    #[DataProvider('defaultConnections')]
    public function test_realtime_follows_the_default_broadcast_connection(?string $connection, bool $expected): void
    {
        config()->set('kinetix.notifications.broadcast', false);
        config()->set('kinetix.broadcasting.echo', null);
        config()->set('broadcasting.default', $connection);

        $this->assertSame($expected, $this->sharedConfig()['realtime']);
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function defaultConnections(): array
    {
        return [
            'reverb' => ['reverb', true],
            'pusher' => ['pusher', true],
            'null'   => ['null', false],
            'log'    => ['log', false],
            'unset'  => [null, false],
            'empty'  => ['', false],
        ];
    }

    public function test_spotlight_is_reported_off_by_default(): void
    {
        $this->assertFalse($this->sharedConfig()['spotlight']['enabled']);
    }

    public function test_spotlight_is_reported_on_when_the_module_is_enabled(): void
    {
        config()->set('kinetix.spotlight.enabled', true);

        $spotlight = $this->sharedConfig()['spotlight'];

        $this->assertTrue($spotlight['enabled']);
        $this->assertIsInt($spotlight['min_chars']);
    }

    /**
     * @return array{realtime: bool, spotlight: array{min_chars: int, enabled: bool}}
     */
    private function sharedConfig(): array
    {
        $shared = Inertia::getShared('kinetix_config');

        /** @var array{realtime: bool, spotlight: array{min_chars: int, enabled: bool}} $resolved */
        $resolved = is_callable($shared) ? $shared() : $shared;

        return $resolved;
    }
}
