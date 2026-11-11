<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Resources\CrudEvent;
use Happones\Kinetix\Resources\CrudNotificationDelivery;
use Happones\Kinetix\Tests\TestCase;

class CrudEventTest extends TestCase
{
    public function test_each_event_maps_to_its_generic_message_key(): void
    {
        $this->assertSame('kinetix.record_created', CrudEvent::Created->messageKey());
        $this->assertSame('kinetix.record_updated', CrudEvent::Updated->messageKey());
        $this->assertSame('kinetix.record_deleted', CrudEvent::Deleted->messageKey());
        $this->assertSame('kinetix.record_restored', CrudEvent::Restored->messageKey());
        $this->assertSame('kinetix.record_force_deleted', CrudEvent::ForceDeleted->messageKey());
    }

    public function test_every_message_key_exists_in_every_shipped_locale(): void
    {
        foreach (['de', 'en', 'es', 'fr', 'ja', 'pt', 'ru', 'zh'] as $locale) {
            $lines = require __DIR__."/../../resources/lang/{$locale}/kinetix.php";

            foreach (CrudEvent::cases() as $event) {
                $key = substr($event->messageKey(), strlen('kinetix.'));

                $this->assertArrayHasKey($key, $lines, "{$locale}: {$event->value}");
                $this->assertNotSame('', $lines[$key]);
            }
        }
    }

    public function test_delivery_truth_table(): void
    {
        $this->assertTrue(CrudNotificationDelivery::Toast->toasts());
        $this->assertFalse(CrudNotificationDelivery::Toast->persists());

        $this->assertFalse(CrudNotificationDelivery::Database->toasts());
        $this->assertTrue(CrudNotificationDelivery::Database->persists());

        $this->assertTrue(CrudNotificationDelivery::ToastAndDatabase->toasts());
        $this->assertTrue(CrudNotificationDelivery::ToastAndDatabase->persists());
    }
}
