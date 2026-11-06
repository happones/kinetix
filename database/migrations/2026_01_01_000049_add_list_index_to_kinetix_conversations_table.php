<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox list reads `where team_id = ? and channel_id in (...) and
 * archived_at is null order by last_message_at desc limit ?`. This index
 * serves the team and archive filters and the order together.
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
    protected string $table = 'kinetix_conversations';

    protected string $index = 'kinetix_conversations_list_index';

    /** @var list<string> */
    protected array $columns = ['team_id', 'archived_at', 'last_message_at'];

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
