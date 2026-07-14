<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Spotlight\SpotlightLink;
use Happones\Kinetix\Spotlight\SpotlightRegistry;
use Happones\Kinetix\Spotlight\SpotlightSource;
use Happones\Kinetix\Tests\Feature\Fixtures\SpotlightFixture\FixtureLinkSource;
use Happones\Kinetix\Tests\TestCase;

class SpotlightDiscoveryTest extends TestCase
{
    protected string $fixturesPath = __DIR__.'/Fixtures/SpotlightFixture';

    protected string $fixturesNamespace = 'Happones\\Kinetix\\Tests\\Feature\\Fixtures\\SpotlightFixture';

    public function test_discovers_and_instantiates_source_classes(): void
    {
        $registry = new SpotlightRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);

        $sources = $registry->sources();

        $this->assertCount(1, $sources);
        $this->assertInstanceOf(FixtureLinkSource::class, $sources[0]);
    }

    public function test_excludes_abstract_and_non_source_classes(): void
    {
        $registry = new SpotlightRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);

        foreach ($registry->sources() as $source) {
            $this->assertInstanceOf(SpotlightSource::class, $source);
        }
    }

    public function test_manual_registration_merges_with_discovery(): void
    {
        $registry = new SpotlightRegistry;
        $registry->discover($this->fixturesPath, $this->fixturesNamespace);
        $registry->register([SpotlightLink::make('Billing')->url('/billing')]);

        $this->assertCount(2, $registry->sources());
    }

    public function test_discovery_on_a_missing_directory_is_a_noop(): void
    {
        $registry = new SpotlightRegistry;
        $registry->discover('/path/does/not/exist', 'Some\\Namespace');

        $this->assertSame([], $registry->sources());
    }
}
