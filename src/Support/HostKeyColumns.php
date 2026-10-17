<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The `user_id` / `team_id` columns Kinetix's migrations create through
 * {@see HostKeys}, checked against the type the app's keys actually have.
 *
 * Before 0.219.15, `auto` key detection read a User/Team model's traits, so
 * one using `HasUlids` only for a public `ulid` next to an auto-increment id
 * got `char(26)` columns holding numbers. The detection is fixed for new
 * migrations; this finds the columns an older install still has, and is what
 * `kinetix:doctor` and `kinetix:fix-key-columns` read.
 */
class HostKeyColumns
{
    /** Database types that hold an integer key. */
    protected const INTEGER_TYPES = ['bigint', 'int', 'integer', 'mediumint', 'smallint', 'int8', 'int4', 'int2'];

    /**
     * Every host-key column the package's migrations declare.
     *
     * @return list<array{table: string, column: string, kind: 'user'|'team'}>
     */
    public static function declared(): array
    {
        $columns = [];

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $const  = preg_match("/const\\s+TABLE\\s*=\\s*'([^']+)'/", $source, $m) === 1 ? $m[1] : null;

            // Each Schema::create/table call opens the table the HostKeys
            // calls after it (and before the next one) belong to.
            preg_match_all("/Schema::(?:create|table)\\(\\s*(?:'([^']+)'|self::TABLE)/", $source, $tables, PREG_OFFSET_CAPTURE);
            preg_match_all("/HostKeys::(user|team)\\(\\s*\\\$table\\s*(?:,\\s*'([^']+)')?/", $source, $keys, PREG_OFFSET_CAPTURE|PREG_SET_ORDER);

            foreach ($keys as $key) {
                $offset = $key[0][1];
                $table  = null;

                foreach ($tables[0] as $i => $call) {
                    if ($call[1] > $offset) {
                        break;
                    }

                    $table = ($tables[1][$i][0] ?? '') !== '' ? $tables[1][$i][0] : $const;
                }

                if ($table === null) {
                    continue;
                }

                $kind   = $key[1][0] === 'team' ? 'team' : 'user';
                $column = ($key[2][0] ?? '') !== '' ? $key[2][0] : "{$kind}_id";

                $columns["{$table}.{$column}"] = ['table' => $table, 'column' => $column, 'kind' => $kind];
            }
        }

        return array_values($columns);
    }

    /**
     * The declared columns whose type in this database is not the one the
     * app's key needs: an integer key in a text column, or the reverse.
     *
     * @return list<array{table: string, column: string, kind: 'user'|'team', expected: string, actual: string, nullable: bool}>
     */
    public static function mismatched(): array
    {
        $found = [];

        foreach (static::declared() as $declared) {
            try {
                if (! Schema::hasTable($declared['table'])) {
                    continue;
                }

                $info = collect(Schema::getColumns($declared['table']))
                    ->firstWhere('name', $declared['column']);
            } catch (Throwable) {
                // No database to read — nothing to say about it.
                return [];
            }

            if (! is_array($info)) {
                continue;
            }

            $expected  = HostKeys::type($declared['kind']);
            $actual    = strtolower((string) ($info['type_name'] ?? ''));
            $isInteger = in_array($actual, static::INTEGER_TYPES, true);

            if (($expected === 'bigint') === $isInteger) {
                continue;
            }

            $found[] = [
                ...$declared,
                'expected' => $expected,
                'actual'   => (string) ($info['type'] ?? $actual),
                'nullable' => (bool) ($info['nullable'] ?? false),
            ];
        }

        return $found;
    }
}
