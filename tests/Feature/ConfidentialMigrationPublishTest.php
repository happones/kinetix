<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\KinetixServiceProvider;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

class ConfidentialMigrationPublishTest extends TestCase
{
    public function test_confidential_migration_is_registered_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            KinetixServiceProvider::class,
            'kinetix-confidential-migrations',
        );

        $published = array_map('basename', array_values($paths));

        $this->assertSame([
            '2026_01_01_000022_create_kinetix_confidential_keys_table.php',
        ], $published);
    }
}
