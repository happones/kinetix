<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the viewer may do on the billing page: `view` reads it (plans,
 * invoices), `manage` changes it (subscribe, payment methods, cancel and
 * resume). Mirrors `KinetixBillingAbilities` in `types/kinetix.ts`.
 */
#[TypeScript]
class BillingAbilitiesData extends Data
{
    public function __construct(public bool $view, public bool $manage) {}
}
