<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A component whose options can come from an Eloquent relationship, and which
 * therefore needs to know the model that *owns* it before it can resolve one.
 *
 * The owner is supplied by whatever is rendering the component — `Table` does it
 * for filters, `Form` for fields — so `relationship('author', 'name')` never has
 * to repeat the related class the relation already names.
 */
interface ResolvesRelationships
{
    /**
     * @param class-string<Model> $modelClass
     */
    public function forModel(string $modelClass): static;
}
