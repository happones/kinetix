<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SpotlightItemData extends Data
{
    public function __construct(
        public string $type,          // resource | link | action
        public string $group,
        public string $title,
        public ?string $subtitle,
        public ?string $url,
        public ?string $event,
        public ?string $icon,
        public int|string|null $id,
    ) {}
}
