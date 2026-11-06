<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypt the webhook endpoint secrets still stored as plaintext (written
 * before secrets were encrypted at rest). Reads and writes the raw column
 * (no model events, no timestamps); a secret already encrypted is left
 * alone, so running it again changes nothing. Signatures don't change: the
 * dispatcher signs with the decrypted secret.
 *
 * Needs the widened column (`kinetix-webhooks-migrations`, 000051).
 */
class WebhooksEncryptSecretsCommand extends Command
{
    protected $signature = 'kinetix:webhooks:encrypt-secrets {--chunk=200 : Rows processed per chunk}';

    protected $description = 'Encrypt Kinetix webhook endpoint secrets still stored as plaintext';

    public function handle(): int
    {
        $model = new WebhookEndpoint;

        if (! EncryptedWithPlaintextFallback::columnHoldsCiphertext($model->getConnectionName(), $model->getTable(), 'secret')) {
            $this->error('The secret column is too small for encrypted secrets. Run: php artisan vendor:publish --tag=kinetix-webhooks-migrations && php artisan migrate');

            return self::FAILURE;
        }

        $chunk     = max(1, (int) $this->option('chunk'));
        $encrypted = 0;

        WebhookEndpoint::query()->toBase()
            ->select([$model->getKeyName(), 'secret'])
            ->chunkById($chunk, function ($rows) use ($model, &$encrypted): void {
                foreach ($rows as $row) {
                    $raw = $row->secret ?? null;

                    if (! is_string($raw) || $raw === '' || EncryptedWithPlaintextFallback::isEncryptedPayload($raw)) {
                        continue;
                    }

                    WebhookEndpoint::query()->toBase()
                        ->where($model->getKeyName(), $row->{$model->getKeyName()})
                        ->update(['secret' => Crypt::encryptString($raw)]);

                    $encrypted++;
                }
            }, $model->getKeyName());

        $this->info("Encrypted {$encrypted} webhook secret(s).");

        return self::SUCCESS;
    }
}
