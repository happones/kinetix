<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class BrowserSessionData extends Data
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public string $browser,
        public string $platform,
        /** desktop | mobile | tablet */
        public string $device,
        public bool $isCurrentDevice,
        public ?string $lastActive,
    ) {}
}
