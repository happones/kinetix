<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Attribute;

/**
 * Marks a property of a {@see BulkAction} or {@see FormAction} subclass that
 * stays on the table: the endpoint's instance keeps what a fresh `make()`
 * gives it instead of getting back the table's value.
 *
 * For a value the action fills in itself while it renders (a memoized form,
 * a cache), never for configuration `handle()` needs.
 *
 *     #[Unsealed]
 *     protected ?array $cachedOptions = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Unsealed {}
