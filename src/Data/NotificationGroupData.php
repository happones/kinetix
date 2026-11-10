<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A named group of team members.
 */
#[TypeScript]
class NotificationGroupData extends Data
{
    /**
     * @param array<int, NotificationAudienceData> $members the group's people who are still members of the team
     */
    public function __construct(
        public int|string $id,
        public string $name,
        public ?string $description,
        public array $members,
    ) {}
}
