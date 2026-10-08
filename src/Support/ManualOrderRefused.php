<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use RuntimeException;

/**
 * A manual order that can't be written: a row it would renumber fails the
 * write check (403), or a list with no positions yet is too long to number in
 * one step (422). Thrown inside the caller's transaction, so nothing is kept.
 * The message is translated and safe to show.
 */
final class ManualOrderRefused extends RuntimeException
{
    public static function forbidden(): self
    {
        return new self((string) __('kinetix.table_write_forbidden'), 403);
    }

    public static function unnumbered(): self
    {
        return new self((string) __('kinetix.table_reorder_unnumbered'), 422);
    }
}
