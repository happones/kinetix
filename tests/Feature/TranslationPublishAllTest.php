<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class TranslationPublishAllTest extends TestCase
{
    protected function setUp(): void
    {
        // ServiceProvider::$publishes is a per-process STATIC registry — clear
        // it so earlier boots (other tests) don't leak their locale maps in.
        foreach (['publishes', 'publishGroups'] as $prop) {
            $ref = new \ReflectionProperty(ServiceProvider::class, $prop);
            $ref->setValue(null, []);
        }

        parent::setUp();
    }

    public function test_all_shipped_locales_publish_by_default(): void
    {
        $paths = ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-translations');

        $published = array_map('basename', array_values($paths));
        sort($published);

        $this->assertSame(['en', 'es', 'fr', 'ja', 'pt', 'ru', 'zh'], $published);
    }
}
