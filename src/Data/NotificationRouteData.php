<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One routing rule: a notification type goes to an audience on some channels.
 */
#[TypeScript]
class NotificationRouteData extends Data
{
    /**
     * @param 'member'|'role'|'group'|'eligible' $audienceType
     * @param list<string>                       $channels
     */
    public function __construct(
        public int|string $id,
        public string $type,
        public string $audienceType,
        /** The member's key, the role's id or the group's id; null for the eligible audience. */
        public ?string $audienceId,
        /** The audience's name; null when it no longer exists (a member who left the team, a deleted role). */
        public ?string $audienceLabel,
        public array $channels,
    ) {}
}
