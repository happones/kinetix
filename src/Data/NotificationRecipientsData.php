<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The dry run of a type's routes: nothing is sent.
 */
#[TypeScript]
class NotificationRecipientsData extends Data
{
    /**
     * @param array<int, NotificationRecipientData> $recipients
     * @param array<int, NotificationRecipientData> $skipped    a sample of the people left out, at most 50
     */
    public function __construct(
        public array $recipients,
        public array $skipped,
        /** How many people were left out in all (`skipped` is only a sample). */
        public int $skippedCount,
        /** The team has at least one rule for the type. */
        public bool $hasRules,
        /** More people than `max_recipients`: the rest would not be notified. */
        public bool $truncated,
        /** No rules apply: `recipients` are the app's default recipients. */
        public bool $fellBack = false,
    ) {}
}
