<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A notification type a team can route, with its rules.
 */
#[TypeScript]
class NotificationRoutingTypeData extends Data
{
    /**
     * @param list<string>                      $channels  the channels the type is delivered on (and the rules may use)
     * @param list<string>                      $mandatory the channels a user cannot opt out of (set in code)
     * @param array<int, NotificationRouteData> $routes
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $channels,
        public array $mandatory,
        /** The ability a recipient must hold, judged when the notification is sent. */
        public ?string $requires,
        public array $routes,
    ) {}
}
