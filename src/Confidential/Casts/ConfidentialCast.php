<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential\Casts;

use Happones\Kinetix\Confidential\Confidential;
use Happones\Kinetix\Confidential\ConfidentialCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

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

    /**
     * Whether `$attribute` of `$model` is cast with this class (with or
     * without colon arguments).
     */
    public static function appliesTo(Model $model, string $attribute): bool
    {
        $cast = $model->getCasts()[$attribute] ?? null;

        return is_string($cast) && ($cast === self::class || str_starts_with($cast, self::class.':'));
    }

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

    /**
     * Encrypt the value, unless it is exactly the mask of what is stored: a
     * masked read written back (`$model->ssn = $model->ssn` while locked, a
     * form that round-tripped the mask) keeps the stored ciphertext instead
     * of replacing the real value with its mask.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value  = (string) $value;
        $stored = $attributes[$key] ?? null;

        $cipher = app(ConfidentialCipher::class);

        if ($stored !== null && str_contains($value, '•') && $value === $this->storedMask($cipher, (string) $stored)) {
            return (string) $stored;
        }

        return $cipher->encrypt($value);
    }

    /**
     * The mask of the stored value, or null when it can't be decrypted (its
     * key is gone): then nothing matches and the new value is encrypted.
     */
    protected function storedMask(ConfidentialCipher $cipher, string $stored): ?string
    {
        try {
            return Confidential::mask($cipher->decrypt($stored), $this->visible, $this->position);
        } catch (RuntimeException) {
            return null;
        }
    }
}
