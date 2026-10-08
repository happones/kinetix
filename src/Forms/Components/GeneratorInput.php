<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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

    /**
     * Named preset from the catalog (`password-strong`, `uuid`, `api-key`,
     * `license-key`, `otp`, `nanoid`, `handle-memorable`, …). Null = built from
     * the legacy kind + knobs. See {@see preset()} and the frontend
     * `GENERATOR_PRESETS`.
     */
    protected ?string $preset = null;

    /** charset | mask | words | uuid | template (null = derived). */
    protected ?string $strategy = null;

    /** Explicit alphabet (overrides the class flags) for a custom charset. */
    protected ?string $alphabet = null;

    /** Mask template for the `mask` strategy (`#`,`A`,`a`,`*`,`H` + literals). */
    protected ?string $mask = null;

    protected ?int $words = null;

    protected ?string $wordSeparator = null;

    protected ?int $appendDigits = null;

    /** Prefix glued before the value (e.g. `sk_` for an API key). */
    protected ?string $valuePrefix = null;

    /**
     * Knobs set explicitly, by config key. With a preset or strategy only
     * these are sent, so a property default (length 16, symbols on, …) never
     * overrides what the preset defines.
     *
     * @var array<string, true>
     */
    protected array $explicitKnobs = [];

    protected function getType(): string
    {
        return 'generator-input';
    }

    /**
     * The names of the built-in presets (mirrors the frontend `GENERATOR_PRESETS`).
     * The actual composition lives on the client — the field only needs to pass
     * the name through, so the catalog stays in ONE place.
     *
     * @var list<string>
     */
    public const PRESETS = [
        'password-strong', 'password-simple', 'password-memorable', 'passphrase',
        'pin-4', 'pin-6', 'otp', 'pin-alphanum',
        'handle', 'handle-memorable',
        'uuid', 'hex', 'hex-64', 'nanoid', 'api-key', 'license-key', 'slug',
    ];

    /**
     * Use a named preset from the catalog. Explicit knobs set afterwards still
     * win, so `->preset('password-strong')->length(24)` overrides just the
     * length.
     *
     *     GeneratorInput::make('token')->preset('api-key');
     *     GeneratorInput::make('serial')->preset('license-key');
     */
    public function preset(string $name): static
    {
        if (! in_array($name, self::PRESETS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown generator preset [%s]. Available presets: %s.',
                $name,
                implode(', ', self::PRESETS),
            ));
        }

        $this->preset = $name;

        return $this;
    }

    /**
     * A fully custom generator: pass either an explicit `alphabet` (+ length)
     * or a `mask` template. This is the escape hatch when no preset fits.
     *
     *     GeneratorInput::make('code')->custom(alphabet: 'ABCDEF0123', length: 8);
     *     GeneratorInput::make('ref')->custom(mask: 'INV-####-AA');
     */
    public function custom(?string $alphabet = null, int $length = 16, ?string $mask = null): static
    {
        if ($mask !== null) {
            $this->strategy = 'mask';
            $this->mask     = $mask;

            return $this->explicit('mask');
        }

        $this->strategy = 'charset';
        $this->alphabet = $alphabet;
        $this->length   = max(1, $length);

        return $this->explicit('alphabet', 'length');
    }

    /**
     * A mask template for the `mask` strategy: `#` digit, `A` upper, `a` lower,
     * `*` alphanumeric, `H` hex; any other character is a literal.
     */
    public function mask(string $mask): static
    {
        $this->strategy = 'mask';
        $this->mask     = $mask;

        return $this->explicit('mask');
    }

    /**
     * An explicit alphabet for the `charset` strategy (overrides class flags).
     */
    public function alphabet(string $alphabet): static
    {
        $this->strategy = 'charset';
        $this->alphabet = $alphabet;

        return $this->explicit('alphabet');
    }

    /**
     * A memorable `adjective-noun…` value, optionally with trailing digits.
     *
     *     GeneratorInput::make('nickname')->words(2, separator: '-', appendDigits: 2);
     */
    public function words(int $count = 3, string $separator = '-', int $appendDigits = 0): static
    {
        $this->strategy      = 'words';
        $this->words         = max(1, $count);
        $this->wordSeparator = $separator;
        $this->appendDigits  = max(0, $appendDigits);

        return $this->explicit('words', 'wordSeparator', 'appendDigits');
    }

    /**
     * Glue a fixed prefix before the generated value (`sk_`, `INV-`, …). This
     * is part of the value, distinct from the field's visual {@see Field::prefix()}.
     */
    public function valuePrefix(string $prefix): static
    {
        $this->valuePrefix = $prefix;

        return $this;
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

        return $this->explicit('length');
    }

    /**
     * PIN preset. `mode`: `numeric` (digits only), `alphanum`, or `alpha`.
     */
    public function pin(int $length = 6, string $mode = 'numeric'): static
    {
        $this->kind    = 'pin';
        $this->length  = max(1, $length);
        $this->pinMode = in_array($mode, ['alpha', 'alphanum', 'numeric'], true) ? $mode : 'numeric';

        return $this->explicit('length');
    }

    /**
     * Username preset. With {@see pattern()} the handle is built from sibling
     * fields; without it, a random lowercase+digits handle of `length`.
     */
    public function username(int $length = 12): static
    {
        $this->kind   = 'username';
        $this->length = max(1, $length);

        return $this->explicit('length');
    }

    /**
     * The username pattern: `{field}` tokens are replaced with sibling values,
     * literal text is kept, and the result is normalized (lowercased, accents
     * stripped, non-alphanumerics collapsed to the separator).
     */
    public function pattern(string $pattern): static
    {
        $this->pattern = $pattern;

        return $this->explicit('pattern');
    }

    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this->explicit('separator');
    }

    public function length(int $length): static
    {
        $this->length = max(1, $length);

        return $this->explicit('length');
    }

    public function lowercase(bool $condition = true): static
    {
        $this->lowercase = $condition;

        return $this->explicit('lowercase');
    }

    public function uppercase(bool $condition = true): static
    {
        $this->uppercase = $condition;

        return $this->explicit('uppercase');
    }

    public function digits(bool $condition = true): static
    {
        $this->digits = $condition;

        return $this->explicit('digits');
    }

    public function symbols(bool $condition = true): static
    {
        $this->symbols = $condition;

        return $this->explicit('symbols');
    }

    /**
     * Override the symbol set used when {@see symbols()} is on.
     */
    public function symbolSet(string $symbols): static
    {
        $this->symbolSet = $symbols;
        $this->symbols   = true;

        return $this->explicit('symbolSet', 'symbols');
    }

    public function excludeAmbiguous(bool $condition = true): static
    {
        $this->excludeAmbiguous = $condition;

        return $this->explicit('excludeAmbiguous');
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

    /**
     * Record config keys as explicitly set, so they override a preset.
     */
    protected function explicit(string ...$keys): static
    {
        foreach ($keys as $key) {
            $this->explicitKnobs[$key] = true;
        }

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $knobs = [
            'length'           => $this->length,
            'lowercase'        => $this->lowercase,
            'uppercase'        => $this->uppercase,
            'digits'           => $this->digits,
            'symbols'          => $this->symbols,
            'symbolSet'        => $this->symbolSet,
            'alphabet'         => $this->alphabet,
            'excludeAmbiguous' => $this->excludeAmbiguous,
            'mask'             => $this->mask,
            'words'            => $this->words,
            'wordSeparator'    => $this->wordSeparator,
            'appendDigits'     => $this->appendDigits,
            'pattern'          => $this->pattern,
            'separator'        => $this->separator,
        ];

        // A preset or strategy carries its own defaults: send only what was
        // set on top of it. Without one, the legacy kind reads the full set,
        // exactly as before presets existed.
        $config = $this->preset !== null || $this->strategy !== null
            ? ['preset' => $this->preset, 'strategy' => $this->strategy] + array_intersect_key($knobs, $this->explicitKnobs)
            : ['kind' => $this->kind, 'pinMode' => $this->pinMode]       + $knobs;

        $config += [
            'prefix'     => $this->valuePrefix,
            'copyable'   => $this->copyable,
            'revealable' => $this->revealable,
        ];

        $data->generatorConfig = array_filter($config, static fn (mixed $value): bool => $value !== null);

        return $data;
    }
}
