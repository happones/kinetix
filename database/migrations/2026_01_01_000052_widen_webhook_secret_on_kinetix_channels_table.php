<?php

declare(strict_types=1);

use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel lines' webhook secrets (what authenticates the provider's calls)
 * are encrypted at rest, and a ciphertext doesn't fit the original
 * `varchar(64)` column: this widens it to TEXT. Until it runs, secrets keep
 * being stored as plaintext (nothing breaks); after it, new lines get an
 * encrypted secret, and `php artisan kinetix:webhooks:encrypt-secrets`
 * encrypts the existing ones.
 *
 * Published with the `kinetix-channels-migrations` tag.
 *
 * Idempotent: it skips a missing table or column, and a column that can
 * already hold a ciphertext. `down()` does nothing: shrinking the column
 * back would truncate the encrypted secrets.
 */
return new class extends Migration
{
    protected string $table = 'kinetix_channels';

    protected string $column = 'webhook_secret';

    public function up(): void
    {
        if (! Schema::hasTable($this->table) || ! Schema::hasColumn($this->table, $this->column)) {
            return;
        }

        if ($this->alreadyWide()) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table): void {
            $table->text($this->column)->change();
        });

        EncryptedWithPlaintextFallback::flush();
    }

    public function down(): void
    {
        // Left wide on purpose: a narrower column would truncate ciphertext.
    }

    protected function alreadyWide(): bool
    {
        foreach (Schema::getColumns($this->table) as $column) {
            if (strcasecmp((string) ($column['name'] ?? ''), $this->column) === 0) {
                return EncryptedWithPlaintextFallback::fits((string) ($column['type_name'] ?? ''), (string) ($column['type'] ?? ''));
            }
        }

        return true;
    }
};
