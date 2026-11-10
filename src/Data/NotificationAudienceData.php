<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A member, a role or a group a rule can point at.
 */
#[TypeScript]
class NotificationAudienceData extends Data
{
    public function __construct(
        public int|string $id,
        public string $name,
    ) {}
}
