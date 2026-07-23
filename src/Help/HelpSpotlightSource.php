<?php

declare(strict_types=1);

namespace Happones\Kinetix\Help;

use Happones\Kinetix\Data\SpotlightItemData;
use Happones\Kinetix\Spotlight\SpotlightSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Surfaces help articles in the global Spotlight. Registered automatically
 * when both `kinetix.help` and `kinetix.spotlight` are enabled. Results run
 * through {@see HelpManager::search()}, so per-article permissions hold.
 *
 * Item URLs resolve through the host's named article route
 * (`kinetix.help.show_route`, default `help.show` — what
 * `kinetix:make-help-page` suggests), falling back to `/help/{slug}`.
 */
class HelpSpotlightSource implements SpotlightSource
{
    public function __construct(protected HelpManager $manager) {}

    public function authorizedFor(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function search(string $query): array
    {
        return array_map(
            fn (array $hit): SpotlightItemData => new SpotlightItemData(
                type: 'link',
                group: (string) trans('kinetix.help_center'),
                title: $hit['title'],
                subtitle: $hit['excerpt'],
                url: $this->articleUrl($hit['slug']),
                event: null,
                icon: 'book-open',
                id: $hit['slug'],
            ),
            $this->manager->search($query, auth()->user()),
        );
    }

    protected function articleUrl(string $slug): string
    {
        $name  = (string) config('kinetix.help.show_route', 'help.show');
        $route = RouteFacade::getRoutes()->getByName($name);

        if ($route === null) {
            return url("/help/{$slug}");
        }

        $params = [];

        foreach ($route->parameterNames() as $param) {
            $params[$param] = $param === 'current_team'
                ? request()->route('current_team')
                : $slug;
        }

        try {
            return route($name, $params);
        } catch (\Throwable) {
            return url("/help/{$slug}");
        }
    }
}
