<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A text input with a one-click VALUE GENERATOR beside it — passwords, PINs,
 * usernames and anything else you can describe with a charset. The generation
 * runs client-side (crypto-strong randomness), so no value round-trips the
 * server before the user has even submitted.
 *
 * Three presets cover the common cases; each is just a bundle of the same
 * underlying knobs (kind + length + charset), so a custom generator is a matter
 * of setting them directly:
 *
 *     GeneratorInput::make('password')->password();                 // 16 chars, all classes
 *     GeneratorInput::make('password')->password(length: 24)->symbols(false);
 *     GeneratorInput::make('pin')->pin(length: 6, mode: 'numeric'); // 000000–999999
 *     GeneratorInput::make('username')->username()->pattern('{first_name}.{last_name}');
 *
 * The same generator ships as a STANDALONE component (`<KinetixGenerator>`) for
 * use outside a form — pointed at any target input; this field is the form
 * wrapper around it. See docs/forms.md.
 */
class GeneratorInput extends Field
{
    /** password | pin | username */
    protected string $kind = 'password';

    protected int $length = 16;

    // Password charset classes.
    protected bool $lowercase = true;

    protected bool $uppercase = true;

    protected bool $digits = true;

    protected bool $symbols = true;

    /** Drop visually ambiguous characters (O/0, l/1/I, …). */
    protected bool $excludeAmbiguous = false;

    /** Explicit symbol set; null = the sensible default on the client. */
    protected ?string $symbolSet = null;

    /** PIN alphabet: alpha | alphanum | numeric. */
    protected string $pinMode = 'numeric';

    /**
     * Username pattern referencing SIBLING fields: `{first_name}.{last_name}`.
     * Resolved on the client from the form's live values (like SlugInput's
     * `from`), then normalized to a safe username. Null = generate a random
     * handle instead.
     */
    protected ?string $pattern = null;

    protected string $separator = '.';

    /** Show a click-to-copy affordance beside the generate button. */
    protected bool $copyable = false;

    /** Keep the generated value visible (false masks it, like a password). */
    protected bool $revealable = true;

    protected function getType(): string
    {
        return 'generator-input';
    }

    /**
     * Password preset: a mixed-class random string. Toggle classes with
     * {@see lowercase()}/{@see uppercase()}/{@see digits()}/{@see symbols()}.
     */
    public function password(int $length = 16): static
    {
        $this->kind       = 'password';
        $this->length     = max(1, $length);
        $this->revealable = true;

        return $this;
    }

    /**
     * PIN preset. `mode`: `numeric` (digits only), `alphanum`, or `alpha`.
     */
    public function pin(int $length = 6, string $mode = 'numeric'): static
    {
        $this->kind    = 'pin';
        $this->length  = max(1, $length);
        $this->pinMode = in_array($mode, ['alpha', 'alphanum', 'numeric'], true) ? $mode : 'numeric';

        return $this;
    }

    /**
     * Username preset. With {@see pattern()} the handle is built from sibling
     * fields; without it, a random lowercase+digits handle of `length`.
     */
    public function username(int $length = 12): static
    {
        $this->kind   = 'username';
        $this->length = max(1, $length);

        return $this;
    }

    /**
     * The username pattern: `{field}` tokens are replaced with sibling values,
     * literal text is kept, and the result is normalized (lowercased, accents
     * stripped, non-alphanumerics collapsed to the separator).
     */
    public function pattern(string $pattern): static
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    public function length(int $length): static
    {
        $this->length = max(1, $length);

        return $this;
    }

    public function lowercase(bool $condition = true): static
    {
        $this->lowercase = $condition;

        return $this;
    }

    public function uppercase(bool $condition = true): static
    {
        $this->uppercase = $condition;

        return $this;
    }

    public function digits(bool $condition = true): static
    {
        $this->digits = $condition;

        return $this;
    }

    public function symbols(bool $condition = true): static
    {
        $this->symbols = $condition;

        return $this;
    }

    /**
     * Override the symbol set used when {@see symbols()} is on.
     */
    public function symbolSet(string $symbols): static
    {
        $this->symbolSet = $symbols;
        $this->symbols   = true;

        return $this;
    }

    public function excludeAmbiguous(bool $condition = true): static
    {
        $this->excludeAmbiguous = $condition;

        return $this;
    }

    public function copyable(bool $condition = true): static
    {
        $this->copyable = $condition;

        return $this;
    }

    /**
     * Mask the generated value behind a reveal toggle (sensible for passwords).
     */
    public function masked(bool $condition = true): static
    {
        $this->revealable = ! $condition;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->generatorConfig = [
            'kind'             => $this->kind,
            'length'           => $this->length,
            'lowercase'        => $this->lowercase,
            'uppercase'        => $this->uppercase,
            'digits'           => $this->digits,
            'symbols'          => $this->symbols,
            'symbolSet'        => $this->symbolSet,
            'excludeAmbiguous' => $this->excludeAmbiguous,
            'pinMode'          => $this->pinMode,
            'pattern'          => $this->pattern,
            'separator'        => $this->separator,
            'copyable'         => $this->copyable,
            'revealable'       => $this->revealable,
        ];

        return $data;
    }
}
