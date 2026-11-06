<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Counts the queries Kinetix itself runs: those whose first stack frame
 * outside `vendor/` is in the package's `src/`. A query the test (or a host
 * controller declared in it) runs is not Kinetix's, and a query a dependency
 * runs on Kinetix's behalf (spatie loading roles) is.
 */
trait CountsKinetixQueries
{
    /** @var list<array{sql: string, origin: string}> */
    protected array $kinetixQueries = [];

    /** @var list<array{sql: string, origin: string}> */
    protected array $otherQueries = [];

    protected bool $countingQueries = false;

    protected bool $queryListenerRegistered = false;

    /**
     * Run `$callback` and return the Kinetix queries it ran.
     *
     * @return list<array{sql: string, origin: string}>
     */
    protected function kinetixQueriesDuring(callable $callback): array
    {
        $this->listenForQueries();

        $this->kinetixQueries  = [];
        $this->otherQueries    = [];
        $this->countingQueries = true;

        try {
            $callback();
        } finally {
            $this->countingQueries = false;
        }

        return $this->kinetixQueries;
    }

    /**
     * @param  list<array{sql: string, origin: string}> $queries
     * @return list<array{sql: string, origin: string}>
     */
    protected function queriesMatching(array $queries, string $pattern): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => preg_match($pattern, $query['sql']) === 1));
    }

    /**
     * @param list<array{sql: string, origin: string}> $queries
     */
    protected function describeQueries(array $queries): string
    {
        return implode("\n", array_map(static fn (array $query): string => $query['origin'].'  '.$query['sql'], $queries));
    }

    private function listenForQueries(): void
    {
        if ($this->queryListenerRegistered) {
            return;
        }

        $this->queryListenerRegistered = true;

        $src    = realpath(__DIR__.'/../../src').DIRECTORY_SEPARATOR;
        $vendor = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;

        DB::listen(function (QueryExecuted $query) use ($src, $vendor): void {
            if (! $this->countingQueries) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = $frame['file'] ?? null;

                if ($file === null || str_contains($file, $vendor) || $file === __FILE__) {
                    continue;
                }

                $entry = ['sql' => $query->sql, 'origin' => basename($file).':'.($frame['line'] ?? 0)];

                if (str_starts_with($file, $src)) {
                    $this->kinetixQueries[] = $entry;
                } else {
                    $this->otherQueries[] = $entry;
                }

                return;
            }
        });
    }
}
