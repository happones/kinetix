<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential\Casts;

use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Encrypts a string attribute at rest and masks it on read unless the
 * current request holds a valid reveal grant (see `Confidential::isUnlocked()`).
 * Enforcement lives here, not in any UI layer — every consumer (Table,
 * Infolist, a Blade view, an API Resource, an Exporter column, tinker) sees
 * the already-masked-or-real value transparently.
 *
 * Per-field overrides via Laravel's colon-argument cast syntax:
 *
 *     protected function casts(): array
 *     {
 *         return ['national_id' => ConfidentialCast::class.':4,tail'];
 *     }
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class ConfidentialCast implements CastsAttributes
{
    public function __construct(protected ?int $visible = null, protected string $position = 'tail') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $plaintext = app(ConfidentialCipher::class)->decrypt((string) $value);

        if (Confidential::isUnlocked()) {
            return $plaintext;
        }

        return Confidential::mask($plaintext, $this->visible, $this->position);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(ConfidentialCipher::class)->encrypt((string) $value);
    }
}
