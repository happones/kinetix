<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a notifiable's key unique per model TYPE in the preferences table
 * (`000039` added the type column).
 *
 * Each step checks what's already there, and the new key is added before the
 * old one goes, so a run that stops halfway is finished by the next one and
 * never leaves the table without a unique key. The index name is short: the
 * generated one, with a table prefix, ran past MySQL's 64 characters. This
 * also repairs a table an earlier `000039` left without its key.
 */
return new class extends Migration
{
    private const TABLE = 'kinetix_notification_preferences';

    private const OWNER_UNIQUE = 'kinetix_notif_prefs_owner_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'notifiable_type')) {
            return;
        }

        if ($this->uniqueOn(['user_id', 'notifiable_type']) === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['user_id', 'notifiable_type'], self::OWNER_UNIQUE);
            });
        }

        $keyOnly = $this->uniqueOn(['user_id']);

        if ($keyOnly !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($keyOnly): void {
                $table->dropUnique($keyOnly);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->uniqueOn(['user_id']) === null) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('user_id');
            });
        }

        $owner = $this->uniqueOn(['user_id', 'notifiable_type']);

        if ($owner !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($owner): void {
                $table->dropUnique($owner);
            });
        }
    }

    /**
     * The name of the table's unique index on exactly these columns, if any.
     *
     * @param list<string> $columns
     */
    private function uniqueOn(array $columns): ?string
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }
};
