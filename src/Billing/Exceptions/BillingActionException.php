<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Exceptions;

use RuntimeException;

/**
 * A billing action refused for a reason the customer can act on (no payment
 * method, trial already used). Its message is translated and meant to be
 * shown; any other exception from a billing action is a fault, reported and
 * answered with a generic message instead of its internals.
 */
class BillingActionException extends RuntimeException
{
    public static function paymentMethodRequired(): self
    {
        return new self((string) __('kinetix.billing_payment_required'));
    }

    public static function trialAlreadyUsed(): self
    {
        return new self((string) __('kinetix.billing_trial_already_used'));
    }

    public static function plansLockedDuringTrial(): self
    {
        return new self((string) __('kinetix.billing_plans_locked_during_trial'));
    }
}
