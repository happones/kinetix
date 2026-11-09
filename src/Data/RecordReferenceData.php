<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Resources\RecordReference;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A record's discreet, copyable reference (see {@see RecordReference}): the
 * part of the identifier shown on screen and the text the copy button writes
 * to the clipboard. The raw value is never sent on its own, so `copyUsing()`
 * decides everything that reaches the browser.
 */
#[TypeScript]
class RecordReferenceData extends Data
{
    /**
     * @param 'start'|'end'|'full' $position Which part of the value `visible` is.
     */
    public function __construct(
        public string $visible,
        public string $position,
        public ?string $label,
        public string $copy,
    ) {}
}
