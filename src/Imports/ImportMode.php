<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

/**
 * What an import does with the records already in the table. An importer
 * opts in by listing the modes its dialog offers ({@see Importer::$importModes});
 * a row is matched to an existing record through {@see Importer::$matchBy}
 * (or a custom resolveRecord()).
 */
enum ImportMode: string
{
    /** Only add: a row that matches an existing record fails. */
    case Create = 'create';

    /** Update the rows that match, add the rest. */
    case Upsert = 'upsert';

    /** Only update: a row that matches nothing fails. */
    case Update = 'update';

    /**
     * The file becomes the active list: rows update (and reactivate) what
     * they match or add the rest, and once the file is done every record
     * missing from it is deactivated — soft deleted, or its active column
     * set to false.
     */
    case Replace = 'replace';

    /**
     * Whether the mode means nothing without a way to match a row to its
     * record ($matchBy or a custom resolveRecord()). Create and Replace work
     * without one: every row is then a new record.
     */
    public function requiresMatching(): bool
    {
        return $this === self::Upsert || $this === self::Update;
    }

    public function label(): string
    {
        return (string) __('kinetix.import_mode_'.$this->value);
    }

    public function description(): string
    {
        return (string) __('kinetix.import_mode_'.$this->value.'_help');
    }
}
