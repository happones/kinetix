<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class TranslationPublishTest extends TestCase
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

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Env-style comma list: an English-only app that also wants Japanese.
        $app['config']->set('kinetix.translations.locales', 'en,ja');
    }

    public function test_only_the_selected_locales_are_registered_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(KinetixServiceProvider::class, 'kinetix-translations');

        $published = array_map('basename', array_values($paths));
        sort($published);

        $this->assertSame(['en', 'ja'], $published);
    }
}
