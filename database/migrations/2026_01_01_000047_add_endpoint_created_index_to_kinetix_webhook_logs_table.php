<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An endpoint's delivery log reads `where webhook_endpoint_id = ? order by
 * created_at desc limit ?`, and every delivery attempt adds a row. This index
 * serves the filter and the order together.
 *
 * Idempotent: it skips a missing table or column, and any index that already
 * starts with these columns in this order — whatever its name, so an
 * equivalent index of the app's counts. `down()` drops only this one.
 *
 * The index name is explicit, so the connection's table prefix is not added
 * to it (43 characters, under MySQL's 64).
 */
return new class extends Migration
{
    protected string $table = 'kinetix_webhook_logs';

    protected string $index = 'kinetix_webhook_logs_endpoint_created_index';

    /** @var list<string> */
    protected array $columns = ['webhook_endpoint_id', 'created_at'];

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
