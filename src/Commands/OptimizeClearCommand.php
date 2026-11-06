<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Support\DiscoveryManifest;
use Illuminate\Console\Command;

/**
 * Removes the cache `kinetix:optimize` wrote: the registries scan their
 * directories again. `php artisan optimize:clear` runs it on Laravel versions
 * with provider optimize hooks.
 */
class OptimizeClearCommand extends Command
{
    protected $signature = 'kinetix:optimize-clear';

    protected $description = 'Remove the cached Kinetix discovery manifest';

    public function handle(): int
    {
        DiscoveryManifest::clear();

        $this->components->info('Kinetix discovery cache cleared.');

        return self::SUCCESS;
    }
}
