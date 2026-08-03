<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The serialized shape of a dashboard widget.
 *
 * `data` stays an open array because every widget type defines its own payload
 * (chart series, stat values, list rows); the envelope around it — id, type,
 * title, span, header actions — is what the frontend relies on, so that part is
 * typed and reaches TypeScript like every other Kinetix payload.
 */
#[TypeScript]
class WidgetData extends Data
{
    /**
     * @param int|string|array<string, mixed>                                  $columnSpan    a grid span, 'full', or a per-breakpoint map
     * @param array<int, array{label: string, url: string, icon: string|null}> $headerActions
     * @param array<string, mixed>                                             $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $title,
        public ?string $description,
        public int|string|array $columnSpan,
        public ?int $sort,
        public array $headerActions,
        public array $data,
    ) {}
}
