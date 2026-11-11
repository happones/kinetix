<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * A lookup query ran past its time budget and the database cancelled it.
 *
 * @see QueryBudget
 */
final class QueryBudgetExceeded extends RuntimeException
{
    public static function from(QueryException $previous): self
    {
        return new self('The lookup query exceeded its time budget.', 0, $previous);
    }
}
