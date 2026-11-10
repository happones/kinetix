<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

/**
 * Who a routed notification reaches, on which channels, and who is left out
 * and why — what {@see PendingRouting::recipients()} returns and what the
 * queued fan-out delivers.
 */
final class RecipientPlan
{
    /** The most people left out that a plan keeps to show; the rest are only counted. */
    public const SKIPPED_SAMPLE = 50;

    /**
     * @param list<PlannedRecipient> $recipients
     * @param list<SkippedRecipient> $skipped      At most {@see SKIPPED_SAMPLE} of the people left out.
     * @param int                    $skippedCount How many people were left out in all.
     * @param bool                   $hasRules     The team has at least one rule for the type.
     * @param bool                   $fellBack     No rules: the host's fallback users were used.
     * @param bool                   $truncated    More recipients than `max_recipients`; the rest were dropped.
     */
    public function __construct(
        public readonly array $recipients,
        public readonly array $skipped,
        public readonly int $skippedCount,
        public readonly bool $hasRules,
        public readonly bool $fellBack,
        public readonly bool $truncated = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->recipients === [];
    }

    /**
     * How many recipients each channel reaches. No names, no keys.
     *
     * @return array<string, int>
     */
    public function countsPerChannel(): array
    {
        $counts = [];

        foreach ($this->recipients as $recipient) {
            foreach ($recipient->channels as $channel) {
                $counts[$channel] = ($counts[$channel] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
