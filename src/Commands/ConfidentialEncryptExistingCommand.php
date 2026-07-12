<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Confidential\Confidential;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Adopts `ConfidentialCast` onto a column that already has real plaintext
 * data: chunks through the table and re-assigns each targeted column to
 * itself, round-tripping it through the cast's `set()` to encrypt it in
 * place. Needed because `ConfidentialCipher`'s legacy-plaintext fallback
 * means old rows read fine but stay unencrypted until migrated.
 */
class ConfidentialEncryptExistingCommand extends Command
{
    protected $signature = 'kinetix:confidential:encrypt-existing
        {model : Fully-qualified Eloquent model class}
        {--column=* : Column(s) to encrypt; defaults to the model\'s confidentialColumns() when it uses HasConfidentialAttributes}
        {--chunk=500 : Rows processed per chunk}';

    protected $description = "Migrate a model column's existing plaintext data through ConfidentialCast, encrypting it in place";

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            $this->error("[{$modelClass}] is not a valid Eloquent model class.");

            return self::FAILURE;
        }

        /** @var array<int, string> $columns */
        $columns = $this->option('column');

        if ($columns === [] && method_exists($modelClass, 'confidentialColumns')) {
            $columns = $modelClass::confidentialColumns();
        }

        if ($columns === []) {
            $this->error('No columns to encrypt — pass --column=... or add HasConfidentialAttributes to the model.');

            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk');
        $total     = 0;

        // `revealed()` is required here: without it, reading `$row->{$column}`
        // through the cast would return the MASKED placeholder (when the
        // console session holds no reveal grant), and re-saving that would
        // destroy the real data instead of encrypting it.
        Confidential::revealed(function () use ($modelClass, $columns, $chunkSize, &$total): void {
            $modelClass::query()->chunkById($chunkSize, function ($rows) use ($columns, &$total): void {
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        $row->{$column} = $row->{$column};
                    }

                    $row->save();
                    $total++;
                }
            });
        });

        $this->info("Encrypted {$total} row(s) across [".implode(', ', $columns)."] on {$modelClass}.");

        return self::SUCCESS;
    }
}
