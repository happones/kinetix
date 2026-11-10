<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A routed notification's audience was worked out. Counts only: no names, no
 * keys.
 */
class AudienceResolved
{
    use Dispatchable;

    /**
     * @param array<string, int> $perChannel recipients each channel reaches
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $team,
        public readonly array $perChannel,
        public readonly int $recipients,
        public readonly int $skipped,
        public readonly bool $hasRules,
        public readonly bool $fellBack,
        public readonly bool $truncated,
    ) {}
}
