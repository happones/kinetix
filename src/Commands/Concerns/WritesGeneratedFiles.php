<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands\Concerns;

use Illuminate\Support\Facades\File;

/**
 * One place where every generator writes a file, so scaffolded source is clean
 * the moment it lands.
 *
 * The specific thing this fixes: a heredoc stub ends at its closing marker with
 * no trailing newline, so `File::put()` produced a file that failed the HOST's
 * own `pint` (`single_blank_line_at_eof`) and `eslint`/`prettier` on its very
 * first run — generated code that arrives already broken by the project's own
 * standards. Normalizing here means a stub author cannot forget it.
 */
trait WritesGeneratedFiles
{
    /**
     * Write generated contents, ending in exactly one newline.
     */
    protected function putGenerated(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, static::normalizeGenerated($contents));
    }

    /**
     * Trailing whitespace collapsed to a single newline. Public-ish (static) so
     * a command with its own write path can still route through it.
     */
    protected static function normalizeGenerated(string $contents): string
    {
        return rtrim($contents, " \t\n\r\0\x0B")."\n";
    }
}
