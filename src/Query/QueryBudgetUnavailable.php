<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

use RuntimeException;

/**
 * The statement limit could not be set; the lookup runs without a budget.
 *
 * @internal
 */
final class QueryBudgetUnavailable extends RuntimeException {}
