<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tags;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Find-or-create tags by name (optionally team-scoped) and sync them onto a
 * taggable model. Tag names are matched/deduped by their slug.
 */
class TagManager
{
    /**
     * The tag names currently attached to a model.
     *
     * @return array<int, string>
     */
    public function for(Model $taggable): array
    {
        /** @var Collection<int, Tag> $tags */
        $tags = $taggable->tags()->orderBy('name')->get();

        return $tags->pluck('name')->all();
    }

    /**
     * Tag names matching a query (autocomplete), team-scoped when given.
     *
     * @return array<int, string>
     */
    public function suggest(string $query, int|string|null $teamId = null, int $limit = 10): array
    {
        return Tag::query()
            ->where('team_id', $teamId)
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%'.trim($query).'%'))
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name')
            ->all();
    }

    /**
     * Replace a model's tags with the given names (find-or-create each).
     *
     * @param array<int, string> $names
     */
    public function sync(Model $taggable, array $names, int|string|null $teamId = null): void
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $ids[] = $this->findOrCreate($name, $teamId)->getKey();
        }

        $taggable->tags()->sync(array_values(array_unique($ids)));
    }

    public function findOrCreate(string $name, int|string|null $teamId = null): Tag
    {
        $slug = Str::slug($name);

        return Tag::query()->firstOrCreate(
            ['team_id' => $teamId, 'slug' => $slug],
            ['name' => $name],
        );
    }

    /**
     * All tag names (team-scoped when given) — e.g. for a table filter.
     *
     * @return array<int, string>
     */
    public function all(int|string|null $teamId = null): array
    {
        return Tag::query()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
