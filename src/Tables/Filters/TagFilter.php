<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Happones\Kinetix\Tags\TagManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Filters rows by Kinetix tags — a multi-select of the existing tag names that
 * matches records carrying any of the selected tags. The table's model must use
 * the HasKinetixTags trait.
 *
 *     TagFilter::make('tags');
 */
class TagFilter extends MultiSelectFilter
{
    /**
     * Tag names populate the multi-select automatically.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        $names = app(TagManager::class)->all();

        return array_combine($names, $names) ?: [];
    }

    /**
     * Match records having any of the selected tags (by slug, via whereHas).
     *
     * @param array<int, mixed>|mixed $value
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        $names = is_array($value) ? $value : [$value];
        $slugs = array_values(array_filter(array_map(
            fn ($v) => $v === null || $v === '' ? null : Str::slug((string) $v),
            $names,
        )));

        if ($slugs === []) {
            return;
        }

        $query->whereHas('tags', fn (Builder $q) => $q->whereIn('slug', $slugs));
    }
}
