<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the webhook secrets still stored as plaintext (written before they
 * were encrypted at rest): the signing secrets of outgoing webhook endpoints
 * and the secrets channel lines authenticate their provider's calls with.
 * Reads and writes the raw columns (no model events, no timestamps); a
 * secret already encrypted is left alone, so running it again changes
 * nothing. Signatures and provider authentication don't change: both use
 * the decrypted secret. A table that doesn't exist (a module not adopted) is
 * skipped.
 *
 * Needs the widened columns (`kinetix-webhooks-migrations` 000051,
 * `kinetix-channels-migrations` 000052).
 */
class WebhooksEncryptSecretsCommand extends Command
{
    protected $signature = 'kinetix:webhooks:encrypt-secrets {--chunk=200 : Rows processed per chunk}';

    protected $description = 'Encrypt Kinetix webhook secrets (webhook endpoints and channel lines) still stored as plaintext';

    public function handle(): int
    {
        $chunk  = max(1, (int) $this->option('chunk'));
        $failed = false;

        foreach ([
            [new WebhookEndpoint, 'secret', 'webhook secret(s)', 'kinetix-webhooks-migrations'],
            [new Channel, 'webhook_secret', 'channel webhook secret(s)', 'kinetix-channels-migrations'],
        ] as [$model, $column, $label, $tag]) {
            if (! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
                continue;
            }

            if (! EncryptedWithPlaintextFallback::columnHoldsCiphertext($model->getConnectionName(), $model->getTable(), $column)) {
                $this->error("The {$model->getTable()}.{$column} column is too small for encrypted secrets. Run: php artisan vendor:publish --tag={$tag} && php artisan migrate");
                $failed = true;

                continue;
            }

            $this->info('Encrypted '.$this->encrypt($model, $column, $chunk)." {$label}.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Encrypt one table's plaintext secrets; the count of rows written. */
    private function encrypt(Model $model, string $column, int $chunk): int
    {
        $key       = $model->getKeyName();
        $encrypted = 0;

        $model->newQueryWithoutScopes()->toBase()
            ->select([$key, $column])
            ->chunkById($chunk, function ($rows) use ($model, $key, $column, &$encrypted): void {
                foreach ($rows as $row) {
                    $raw = $row->{$column} ?? null;

                    if (! is_string($raw) || $raw === '' || EncryptedWithPlaintextFallback::isEncryptedPayload($raw)) {
                        continue;
                    }

                    $model->newQueryWithoutScopes()->toBase()
                        ->where($key, $row->{$key})
                        ->update([$column => Crypt::encryptString($raw)]);

                    $encrypted++;
                }
            }, $key);

        return $encrypted;
    }
}
