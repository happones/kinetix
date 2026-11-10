<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One person in a recipients preview: who would be notified and on which
 * channels, or who is left out and why.
 */
#[TypeScript]
class NotificationRecipientData extends Data
{
    /**
     * @param list<string>                                $channels empty for a skipped person
     * @param 'ineligible'|'opted_out'|'no_channels'|null $reason   null for someone who would be notified
     */
    public function __construct(
        public int|string $id,
        public string $name,
        public array $channels,
        public ?string $reason,
    ) {}
}
