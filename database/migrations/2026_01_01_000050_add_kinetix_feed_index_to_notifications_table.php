<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification bell reads the user's latest notifications (`where
 * notifiable_type = ? and notifiable_id = ? order by created_at desc limit
 * ?`) on every page and on every poll. Laravel's own notifications table only
 * indexes the two notifiable columns, so the database sorts every
 * notification the user ever received to return the newest few. This index
 * serves the filter and the order together.
 *
 * Optional: the table is the app's own (`php artisan
 * make:notifications-table`). Published with the
 * `kinetix-notifications-migrations` tag.
 *
 * Idempotent: it skips a missing table or column, and any index that already
 * starts with these columns in this order — whatever its name, so an
 * equivalent index of the app's counts. `down()` drops only this one.
 *
 * The index name is explicit, so the connection's table prefix is not added
 * to it (32 characters, under MySQL's 64).
 */
return new class extends Migration
{
    protected string $table = 'notifications';

    protected string $index = 'kinetix_notifications_feed_index';

    /** @var list<string> */
    protected array $columns = ['notifiable_type', 'notifiable_id', 'created_at'];

    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        foreach ($this->columns as $column) {
            if (! Schema::hasColumn($this->table, $column)) {
                return;
            }
        }

        if ($this->hasEquivalentIndex()) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table): void {
            $table->index($this->columns, $this->index);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table) || ! $this->hasOwnIndex()) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table): void {
            $table->dropIndex($this->index);
        });
    }

    /**
     * Whether an index starts with exactly these columns, in this order.
     */
    protected function hasEquivalentIndex(): bool
    {
        $expected = array_map('strtolower', $this->columns);

        foreach (Schema::getIndexes($this->table) as $index) {
            $columns = array_map(static fn (mixed $column): string => strtolower((string) $column), (array) ($index['columns'] ?? []));

            if (array_slice($columns, 0, count($expected)) === $expected) {
                return true;
            }
        }

        return false;
    }

    protected function hasOwnIndex(): bool
    {
        foreach (Schema::getIndexes($this->table) as $index) {
            if (strtolower((string) ($index['name'] ?? '')) === $this->index) {
                return true;
            }
        }

        return false;
    }
};
