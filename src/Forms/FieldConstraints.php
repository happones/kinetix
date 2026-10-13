<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Forms\Components\FieldCondition;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\ImageFile;
use Illuminate\Validation\Rules\Password;
use ReflectionProperty;
use Stringable;
use Throwable;

/**
 * A field's Laravel validation rules as the browser can use them: what the
 * value must look like, so the input can say so before the server does.
 *
 * Each rule family becomes one entry, read the way Laravel reads it — `max:10`
 * is a length for a string, a value for a number, a count for an array and
 * kilobytes for a file (the field's other rules and its type decide which).
 * Rules that need the database (`unique`, `exists`), closures and custom rule
 * objects say nothing the browser can check, and are left to the server,
 * which validates everything again.
 *
 * {@see conditions()} turns `required_if`, `required_with`, `exclude_if` and
 * friends into the field conditions the form evaluates live.
 */
final class FieldConstraints
{
    private const FORMATS = [
        'email'       => 'email',
        'url'         => 'url',
        'active_url'  => 'url',
        'ip'          => 'ip',
        'ipv4'        => 'ipv4',
        'ipv6'        => 'ipv6',
        'mac_address' => 'mac',
        'uuid'        => 'uuid',
        'ulid'        => 'ulid',
        'hex_color'   => 'hex_color',
        'json'        => 'json',
        'timezone'    => 'timezone',
    ];

    private const NUMERIC_TYPES = ['number-field', 'slider', 'rating'];

    private const ARRAY_TYPES = ['checkbox-list', 'tags-input', 'key-value', 'repeater', 'table-repeater'];

    private const FILE_TYPES = ['file-upload', 'media-library'];

    /**
     * @param  list<mixed>               $rules the field's resolved rules
     * @param  string                    $type  the field's type (`text-input`, `number-field`, …)
     * @return array<string, mixed>|null null when nothing applies
     */
    public static function from(array $rules, string $type = 'text-input'): ?array
    {
        $tokens = self::tokens($rules);
        $kind   = self::kind($tokens, $type);
        $c      = [];

        if (isset($tokens['required'])) {
            $c['required'] = true;
        }

        self::sizes($tokens, $kind, $c);

        if (isset($tokens['integer'])) {
            $c['integer'] = true;
        }

        if (isset($tokens['multiple_of'][0]) && is_numeric($tokens['multiple_of'][0])) {
            $c['step'] = $tokens['multiple_of'][0] + 0;
        }

        if (isset($tokens['decimal'])) {
            $min           = (int) ($tokens['decimal'][0] ?? 0);
            $c['decimals'] = ['min' => $min, 'max' => (int) ($tokens['decimal'][1] ?? $min)];
        }

        self::digits($tokens, $c);

        foreach (['alpha_dash', 'alpha_num', 'alpha', 'ascii'] as $charset) {
            if (isset($tokens[$charset])) {
                $c['charset'] = $charset;

                break;
            }
        }

        if (isset($tokens['lowercase'])) {
            $c['case'] = 'lower';
        } elseif (isset($tokens['uppercase'])) {
            $c['case'] = 'upper';
        }

        foreach (['starts_with' => 'startsWith', 'ends_with' => 'endsWith', 'doesnt_start_with' => 'notStartsWith', 'doesnt_end_with' => 'notEndsWith', 'not_in' => 'notIn'] as $rule => $key) {
            if (isset($tokens[$rule]) && $tokens[$rule] !== []) {
                $c[$key] = $tokens[$rule];
            }
        }

        foreach (['regex' => 'pattern', 'not_regex' => 'notPattern'] as $rule => $key) {
            if (isset($tokens[$rule]) && ($pattern = self::jsPattern(implode(',', $tokens[$rule]))) !== null) {
                $c[$key] = $pattern;
            }
        }

        foreach (self::FORMATS as $rule => $format) {
            if (isset($tokens[$rule])) {
                $c['format'] = $format;

                break;
            }
        }

        if (isset($tokens['distinct'])) {
            $c['distinct'] = in_array('ignore_case', $tokens['distinct'], true) ? 'ignore_case' : true;
        }

        // `array:title,color`: the keys the value may have.
        if (isset($tokens['array']) && $tokens['array'] !== []) {
            $c['keys'] = $tokens['array'];
        }

        self::dates($tokens, $c);
        self::comparisons($tokens, $c);

        if (isset($tokens['confirmed'])) {
            $c['confirmed'] = true;
        }

        if (isset($tokens['password:object'])) {
            $c['password'] = $tokens['password:object'];
        }

        if ($kind === 'file') {
            self::files($tokens, $c);
        }

        return $c === [] ? null : ['kind' => $kind] + $c;
    }

    /**
     * The conditions that `required_if`, `required_unless`, `required_with`,
     * `required_without`, `prohibited_if` and `exclude_if` describe, by
     * effect. Only what one other field decides can be said this way; a rule
     * naming several fields (`required_with_all:a,b`) stays with the server.
     *
     * @param  list<mixed>                   $rules
     * @return array<string, FieldCondition>
     */
    public static function conditions(array $rules): array
    {
        $tokens     = self::tokens($rules);
        $conditions = [];

        $when = static function (array $parameters, bool $negate): ?FieldCondition {
            if (count($parameters) < 2) {
                return null;
            }

            $field  = array_shift($parameters);
            $values = array_map(self::conditionValue(...), $parameters);

            return count($values) === 1
                ? new FieldCondition($field, $negate ? FieldCondition::NOT_EQUALS : FieldCondition::EQUALS, $values[0])
                : new FieldCondition($field, $negate ? FieldCondition::NOT_IN : FieldCondition::IN, $values);
        };

        $required = match (true) {
            isset($tokens['required_if'])                                                  => $when($tokens['required_if'], false),
            isset($tokens['required_unless'])                                              => $when($tokens['required_unless'], true),
            isset($tokens['required_with'])    && count($tokens['required_with'])    === 1 => new FieldCondition($tokens['required_with'][0], FieldCondition::FILLED),
            isset($tokens['required_without']) && count($tokens['required_without']) === 1 => new FieldCondition($tokens['required_without'][0], FieldCondition::BLANK),
            default                                                                        => null,
        };

        if ($required !== null) {
            $conditions['required'] = $required;
        }

        $hidden = match (true) {
            isset($tokens['exclude_if'])     => $when($tokens['exclude_if'], false),
            isset($tokens['exclude_unless']) => $when($tokens['exclude_unless'], true),
            isset($tokens['prohibited_if'])  => $when($tokens['prohibited_if'], false),
            default                          => null,
        };

        if ($hidden !== null) {
            $conditions['hidden'] = $hidden;
        }

        return $conditions;
    }

    /**
     * Laravel's `required_if:accepted,true` compares against the string; a
     * boolean field sends true/false, so the literals become booleans.
     */
    private static function conditionValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => $value,
        };
    }

    /**
     * How Laravel reads `size`/`min`/`max`/`between` for this field.
     *
     * @param array<string, mixed> $tokens
     */
    private static function kind(array $tokens, string $type): string
    {
        return match (true) {
            isset($tokens['file'])    || isset($tokens['image']) || isset($tokens['mimes']) || isset($tokens['mimetypes']) || isset($tokens['extensions']) || in_array($type, self::FILE_TYPES, true) => 'file',
            isset($tokens['array'])   || isset($tokens['list']) || in_array($type, self::ARRAY_TYPES, true)                                                                                           => 'array',
            isset($tokens['numeric']) || isset($tokens['integer']) || isset($tokens['decimal']) || in_array($type, self::NUMERIC_TYPES, true)                                                         => 'numeric',
            default                                                                                                                                                                                   => 'string',
        };
    }

    /**
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $c
     */
    private static function sizes(array $tokens, string $kind, array &$c): void
    {
        $bounds = [];

        if (isset($tokens['size'][0]) && is_numeric($tokens['size'][0])) {
            $bounds['exact'] = $tokens['size'][0] + 0;
        }

        if (isset($tokens['between'][0], $tokens['between'][1]) && is_numeric($tokens['between'][0]) && is_numeric($tokens['between'][1])) {
            $bounds['min'] = $tokens['between'][0] + 0;
            $bounds['max'] = $tokens['between'][1] + 0;
        }

        foreach (['min', 'max'] as $rule) {
            if (isset($tokens[$rule][0]) && is_numeric($tokens[$rule][0])) {
                $bounds[$rule] = $tokens[$rule][0] + 0;
            }
        }

        // A number can also be bounded by a value it can't reach.
        if ($kind === 'numeric') {
            foreach (['gt' => 'gt', 'lt' => 'lt', 'gte' => 'min', 'lte' => 'max'] as $rule => $key) {
                if (isset($tokens[$rule][0]) && is_numeric($tokens[$rule][0])) {
                    $bounds[$key] = $tokens[$rule][0] + 0;
                }
            }
        }

        if ($bounds === []) {
            return;
        }

        $key     = ['string' => 'length', 'numeric' => 'range', 'array' => 'items', 'file' => 'kilobytes'][$kind];
        $c[$key] = $bounds;
    }

    /**
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $c
     */
    private static function digits(array $tokens, array &$c): void
    {
        $digits = match (true) {
            isset($tokens['digits'][0])         => ['min' => (int) $tokens['digits'][0], 'max' => (int) $tokens['digits'][0]],
            isset($tokens['digits_between'][1]) => ['min' => (int) $tokens['digits_between'][0], 'max' => (int) $tokens['digits_between'][1]],
            default                             => [],
        };

        if (isset($tokens['min_digits'][0])) {
            $digits['min'] = (int) $tokens['min_digits'][0];
        }

        if (isset($tokens['max_digits'][0])) {
            $digits['max'] = (int) $tokens['max_digits'][0];
        }

        if ($digits !== []) {
            $c['digits'] = $digits;
        }
    }

    /**
     * `after:today`, `before:end_date`, `date_equals:2026-01-01`: a date or
     * another field's value.
     *
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $c
     */
    private static function dates(array $tokens, array &$c): void
    {
        $dates = [];

        foreach (['after', 'after_or_equal', 'before', 'before_or_equal', 'date_equals'] as $rule) {
            $target = $tokens[$rule][0] ?? null;

            if (! is_string($target) || $target === '') {
                continue;
            }

            $dates[] = ['op' => $rule === 'date_equals' ? 'equals' : $rule] + (self::isDateLiteral($target)
                ? ['date' => strtolower($target)]
                : ['field' => $target]);
        }

        if ($dates !== []) {
            $c['dates'] = $dates;
        }
    }

    private static function isDateLiteral(string $value): bool
    {
        return in_array(strtolower($value), ['today', 'tomorrow', 'yesterday', 'now'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    /**
     * `gt:field`, `lte:other`, `same:password`, `different:old`: checked
     * against another field's current value.
     *
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $c
     */
    private static function comparisons(array $tokens, array &$c): void
    {
        $compare = [];

        foreach (['gt', 'gte', 'lt', 'lte', 'same', 'different'] as $rule) {
            $field = $tokens[$rule][0] ?? null;

            if (is_string($field) && $field !== '' && ! is_numeric($field)) {
                $compare[] = ['op' => $rule, 'field' => $field];
            }
        }

        if ($compare !== []) {
            $c['compare'] = $compare;
        }
    }

    /**
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $c
     */
    private static function files(array $tokens, array &$c): void
    {
        // `File::types()` holds extensions and MIME types alike: a slash tells
        // them apart, as Laravel does.
        $declared  = [...($tokens['mimes'] ?? []), ...($tokens['extensions'] ?? []), ...($tokens['mimetypes'] ?? []), ...($tokens['file:object']['types'] ?? [])];
        $extension = array_values(array_unique(array_map('strtolower', array_filter($declared, static fn (string $type): bool => ! str_contains($type, '/')))));
        $mimeTypes = array_values(array_unique(array_map('strtolower', array_filter($declared, static fn (string $type): bool => str_contains($type, '/')))));

        if ($extension !== []) {
            $c['fileTypes'] = $extension;
        }

        if ($mimeTypes !== []) {
            $c['mimeTypes'] = $mimeTypes;
        }

        if (isset($tokens['image'])) {
            $c['image'] = true;
        }

        foreach (['min' => 'min', 'max' => 'max'] as $bound => $key) {
            if (isset($tokens['file:object'][$bound])) {
                $c['kilobytes'][$key] = $tokens['file:object'][$bound];
            }
        }

        if (isset($tokens['dimensions'])) {
            $dimensions = [];

            foreach ($tokens['dimensions'] as $pair) {
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);

                if ($value === null) {
                    continue;
                }

                $dimensions[lcfirst(str_replace('_', '', ucwords(trim($name), '_')))] = $name === 'ratio' ? self::ratio($value) : (int) $value;
            }

            if ($dimensions !== []) {
                $c['dimensions'] = $dimensions;
            }
        }
    }

    private static function ratio(string $value): float
    {
        if (str_contains($value, '/')) {
            [$width, $height] = explode('/', $value, 2);

            return (float) $height === 0.0 ? 0.0 : round((float) $width / (float) $height, 6);
        }

        return (float) $value;
    }

    /**
     * A PHP regex (`/^[A-Z]{3}$/i`) as a JavaScript source and flags, when it
     * translates: a `/` or `#` delimiter and only the `i`, `m`, `s`, `u`
     * flags.
     *
     * @return array{source: string, flags: string}|null
     */
    private static function jsPattern(string $regex): ?array
    {
        if (preg_match('/^([\/#])(.*)\1([imsu]*)$/s', $regex, $match) !== 1) {
            return null;
        }

        return ['source' => $match[2], 'flags' => $match[3]];
    }

    /**
     * A field's rules as name => parameters, rule objects read by their kind
     * (`Password`, `File`, `Rule::enum()`) or their string form (`Rule::in()`,
     * `Rule::dimensions()`). Closures and custom rules are skipped.
     *
     * @param  list<mixed>          $rules
     * @return array<string, mixed>
     */
    private static function tokens(array $rules): array
    {
        $tokens = [];

        foreach ($rules as $rule) {
            if ($rule instanceof Password) {
                $tokens['password:object'] = self::password($rule);

                continue;
            }

            if ($rule instanceof File) {
                $tokens['file']        = [];
                $tokens['file:object'] = self::file($rule);

                if ($rule instanceof ImageFile) {
                    $tokens['image'] = [];
                }

                continue;
            }

            if ($rule instanceof Enum) {
                continue;
            }

            if ($rule instanceof Stringable) {
                $rule = (string) $rule;
            }

            if (! is_string($rule) || $rule === '') {
                continue;
            }

            foreach (explode('|', $rule) as $part) {
                [$name, $parameters] = array_pad(explode(':', $part, 2), 2, null);
                $name                = strtolower(trim($name));

                // A regex keeps its commas and pipes; everything else is a list.
                $tokens[$name] = match (true) {
                    $parameters === null                          => [],
                    in_array($name, ['regex', 'not_regex'], true) => [$parameters],
                    default                                       => array_map(
                        static fn (?string $value): string => trim((string) $value),
                        str_getcsv($parameters, ',', '"', ''),
                    ),
                };
            }
        }

        return $tokens;
    }

    /**
     * @return array{min: int, letters: bool, mixedCase: bool, numbers: bool, symbols: bool, uncompromised: bool}
     */
    private static function password(Password $rule): array
    {
        $read = static function (string $property, mixed $default) use ($rule): mixed {
            try {
                return (new ReflectionProperty($rule, $property))->getValue($rule);
            } catch (Throwable) {
                return $default;
            }
        };

        return [
            'min'           => (int) $read('min', 8),
            'letters'       => (bool) $read('letters', false),
            'mixedCase'     => (bool) $read('mixedCase', false),
            'numbers'       => (bool) $read('numbers', false),
            'symbols'       => (bool) $read('symbols', false),
            'uncompromised' => (bool) $read('uncompromised', false),
        ];
    }

    /**
     * @return array{types?: list<string>, min?: int|float, max?: int|float}
     */
    private static function file(File $rule): array
    {
        $read = static function (string $property) use ($rule): mixed {
            try {
                return (new ReflectionProperty($rule, $property))->getValue($rule);
            } catch (Throwable) {
                return null;
            }
        };

        $types   = array_values(array_filter([...(array) ($read('allowedMimetypes') ?? []), ...(array) ($read('allowedExtensions') ?? [])], 'is_string'));
        $summary = [];

        if ($types !== []) {
            $summary['types'] = $types;
        }

        foreach (['min' => 'minimumFileSize', 'max' => 'maximumFileSize'] as $key => $property) {
            $size = $read($property);

            if (is_int($size) || is_float($size)) {
                $summary[$key] = $size;
            }
        }

        return $summary;
    }
}
