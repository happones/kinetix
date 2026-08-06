<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Exceptions;

use Happones\Kinetix\Billing\Concerns\HasMeteredUsage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown by {@see HasMeteredUsage::consume()}
 * when a consumption would exceed the plan allowance plus the remaining
 * credits. Renders as a 403; `remaining` carries how many units were still
 * available (0 when fully exhausted) so callers can offer a precise upsell.
 */
class UsageLimitExceededException extends HttpException
{
    public function __construct(
        public readonly string $key,
        public readonly int $remaining,
        int $limit = 0,
    ) {
        parent::__construct(403, (string) __('kinetix.plan_limit_reached', [
            'key'   => str($key)->headline()->lower()->toString(),
            'limit' => $limit,
        ]));
    }
}
