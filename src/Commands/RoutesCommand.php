<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * List the endpoints Kinetix registers at runtime, with their **resolved** URIs.
 *
 * Kinetix components never call app routes: each module mounts its own endpoints
 * under `{current_team}/{kinetix.route_prefix}/…` and the published Vue
 * components build that URL from the Inertia props. The most common integration
 * mistake is writing a controller under a *different* prefix and wondering why
 * it never runs — this command shows exactly what the frontend talks to.
 */
class RoutesCommand extends Command
{
    protected $signature = 'kinetix:routes
        {filter? : Only show routes whose URI or name contains this string}
        {--json : Output the routes as JSON}';

    protected $description = 'List the endpoints Kinetix registers (the URLs its components call)';

    public function handle(): int
    {
        $filter = $this->argument('filter');
        $routes = $this->kinetixRoutes(is_string($filter) ? $filter : null);

        if ($this->option('json')) {
            $this->line((string) json_encode($routes, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $prefix = (string) config('kinetix.route_prefix', '_kinetix');
        $teams  = (bool) config('kinetix.teams', false);

        $this->newLine();
        $this->line('  <options=bold>Kinetix endpoints</> live under <fg=yellow>'
            .($teams ? '{current_team}/'.$prefix : $prefix).'/…</>');
        $this->line('  <fg=gray>Published components call these URLs themselves — never register your own</>');
        $this->line('  <fg=gray>controller under a different prefix expecting the frontend to hit it.</>');
        $this->newLine();

        if ($routes === []) {
            $this->warn('  No Kinetix routes are registered. Every module is opt-in — enable it in config/kinetix.php.');
            $this->newLine();

            return self::SUCCESS;
        }

        // Middleware only with -v: it is long enough to wrap the URI column on a
        // standard terminal, which defeats the point of the listing.
        $verbose = $this->output->isVerbose();

        $this->table(
            array_merge(['Method', 'URI', 'Name'], $verbose ? ['Middleware'] : []),
            array_map(static fn (array $route): array => array_merge([
                $route['method'],
                $route['uri'],
                $route['name'] ?? '',
            ], $verbose ? [implode(', ', $route['middleware'])] : []), $routes),
        );

        $this->line('  <fg=gray>'.count($routes).' endpoint(s). Route prefix: <fg=yellow>'.$prefix.'</> '
            .'(KINETIX_ROUTE_PREFIX) · teams: '.($teams ? 'on' : 'off').'</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Kinetix's own routes: anything named `kinetix.*` plus anything mounted
     * under the configured prefix (a host route reusing the prefix shows up too
     * — which is exactly the collision worth seeing).
     *
     * @return array<int, array{method: string, uri: string, name: string|null, middleware: array<int, string>}>
     */
    protected function kinetixRoutes(?string $filter): array
    {
        $prefix = trim((string) config('kinetix.route_prefix', '_kinetix'), '/');
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $uri  = $route->uri();

            $isKinetix = ($name !== null && str_starts_with($name, 'kinetix.'))
                || ($prefix !== '' && str_contains($uri, $prefix.'/'));

            if (! $isKinetix) {
                continue;
            }

            if ($filter !== null && ! str_contains($uri, $filter) && ! str_contains((string) $name, $filter)) {
                continue;
            }

            $routes[] = [
                'method'     => implode('|', array_diff($route->methods(), ['HEAD'])),
                'uri'        => '/'.$uri,
                'name'       => $name,
                'middleware' => array_values(array_map('strval', $route->gatherMiddleware())),
            ];
        }

        usort($routes, static fn (array $a, array $b): int => $a['uri'] <=> $b['uri']);

        return $routes;
    }
}
