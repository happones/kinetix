<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRegistry;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Spotlight\SpotlightRegistry;
use Happones\Kinetix\Spotlight\SpotlightSource;
use Happones\Kinetix\Support\DiscoveryManifest;
use Illuminate\Console\Command;

/**
 * Caches the classes Kinetix discovers by scanning directories (permission
 * matrix Resources, Spotlight sources, Reports Center reports), so production
 * requests stop walking those folders. `php artisan optimize` runs it on
 * Laravel versions with provider optimize hooks; add it to the deploy script
 * otherwise. Re-run it after adding a class in production, as with
 * `config:cache`.
 */
class OptimizeCommand extends Command
{
    protected $signature = 'kinetix:optimize';

    protected $description = 'Cache the classes Kinetix discovers by scanning directories';

    public function handle(): int
    {
        $entries = [];

        foreach ($this->registries() as [$registry, $parent]) {
            foreach ($registry->discoveryRoots() as [$directory, $namespace]) {
                $entries[DiscoveryManifest::key($directory, $namespace, $parent)] = array_values(
                    $registry->scanDirectoryForSubclasses($directory, $namespace, $parent),
                );
            }
        }

        DiscoveryManifest::write($entries);

        $this->components->info('Kinetix discovery cached ('.count($entries).' '.(count($entries) === 1 ? 'root' : 'roots').').');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: PermissionRegistry|SpotlightRegistry|ReportRegistry, 1: class-string}>
     */
    protected function registries(): array
    {
        return [
            [$this->laravel->make(PermissionRegistry::class), Resource::class],
            [$this->laravel->make(SpotlightRegistry::class), SpotlightSource::class],
            [$this->laravel->make(ReportRegistry::class), Report::class],
        ];
    }
}
