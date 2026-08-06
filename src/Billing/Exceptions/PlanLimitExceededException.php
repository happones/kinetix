<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Exceptions;

use Happones\Kinetix\Billing\Concerns\EnforcesPlanLimits;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown by {@see EnforcesPlanLimits} when
 * creating a record would exceed the billable's plan limit. Renders as a 403
 * with a translated message; catch it around bulk operations to handle the
 * limit yourself (the key and limit are carried as public properties).
 */
class PlanLimitExceededException extends HttpException
{
    public function __construct(
        public readonly string $limitKey,
        public readonly int $limit,
    ) {
        parent::__construct(403, (string) __('kinetix.plan_limit_reached', [
            'key'   => str($limitKey)->headline()->lower()->toString(),
            'limit' => $limit,
        ]));
    }
}
