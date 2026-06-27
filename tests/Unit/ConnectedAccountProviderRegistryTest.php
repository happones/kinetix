<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\ConnectedAccounts\ConnectedAccountProviderRegistry;
use Happones\Kinetix\Tests\TestCase;

class ConnectedAccountProviderRegistryTest extends TestCase
{
    public function test_normalizes_array_and_string_definitions(): void
    {
        $registry = new ConnectedAccountProviderRegistry;
        $registry->register([
            'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
            'gitlab' => 'GitLab',
        ]);

        $all = $registry->all();

        $this->assertSame(['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'], $all['github']);
        // A string value becomes the label; icon defaults to the key, color null.
        $this->assertSame(['label' => 'GitLab', 'icon' => 'gitlab', 'color' => null], $all['gitlab']);
    }

    public function test_has_and_keys(): void
    {
        $registry = new ConnectedAccountProviderRegistry;
        $registry->register(['google' => 'Google']);

        $this->assertTrue($registry->has('google'));
        $this->assertFalse($registry->has('github'));
        $this->assertSame(['google'], $registry->keys());
    }
}
