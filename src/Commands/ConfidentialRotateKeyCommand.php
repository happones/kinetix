<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Confidential\ConfidentialKey;
use Happones\Kinetix\Confidential\KeyManagers\KeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Generates the first confidential-fields encryption key, or rotates to a
 * new one: the previous current key is retired (kept, so historical
 * envelopes encrypted under it stay decryptable) and a fresh key becomes
 * current for new writes.
 */
class ConfidentialRotateKeyCommand extends Command
{
    protected $signature = 'kinetix:confidential:rotate-key';

    protected $description = 'Generate a new Kinetix confidential-fields encryption key and make it current';

    public function handle(KeyManager $keyManager): int
    {
        $previous = ConfidentialKey::query()->where('is_current', true)->first();

        if ($previous !== null) {
            $previous->update(['is_current' => false, 'retired_at' => now()]);
        }

        $generated = $keyManager->generateDataKey();

        ConfidentialKey::create([
            'key_id'      => (string) Str::ulid(),
            'driver'      => (string) config('kinetix.confidential.key_manager', 'local'),
            'wrapped_key' => $generated['wrapped'],
            'is_current'  => true,
        ]);

        Cache::forget('kinetix-confidential-current-key');

        $this->info($previous === null
            ? 'Generated the initial Kinetix confidential-fields encryption key.'
            : 'Rotated to a new Kinetix confidential-fields encryption key. The previous key is retained for decrypting existing data.');

        return self::SUCCESS;
    }
}
