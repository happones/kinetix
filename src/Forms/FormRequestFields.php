<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Forms\Components\Checkbox;
use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\DatePicker;
use Happones\Kinetix\Forms\Components\DateTimePicker;
use Happones\Kinetix\Forms\Components\Field;
use Happones\Kinetix\Forms\Components\FileUpload;
use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TagsInput;
use Happones\Kinetix\Forms\Components\Textarea;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\ImageFile;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use LogicException;
use ReflectionProperty;
use Stringable;
use Throwable;

/**
 * The fields a FormRequest's rules describe: each top-level rule becomes the
 * input that collects it — `boolean` a toggle, `in:`/`Rule::enum()` a select,
 * `date` a date picker, `numeric` a number field, `email`/`url`/password
 * rules a text input of that kind, `file`/`image` an upload, a long `max:` a
 * textarea, `confirmed` a second field for the confirmation. `required`,
 * `max`/`min` and the request's `attributes()` (labels) carry over.
 *
 * The rules say what to validate, not how to ask for it, so the guess is a
 * start: a relation id (`exists:`) is a plain input, and nested rules
 * (`items.*.qty`) are left out. Shape the form yourself with a {@see Form}
 * class — `kinetix:make-form --from-request` writes one from the same guess.
 *
 * The rules are read with the current request, outside the route they
 * belong to: one that needs the route's model (`$this->post->id`) can't be
 * read here.
 */
final class FormRequestFields
{
    /** A string longer than this gets a textarea. */
    private const TEXTAREA_OVER = 255;

    /**
     * The fields, as components.
     *
     * @param  class-string<FormRequest> $request
     * @return list<Field>
     */
    public static function components(string $request): array
    {
        return array_map(static function (array $spec): Field {
            $field = $spec['component']::make($spec['name']);

            foreach ($spec['calls'] as [$method, $arguments]) {
                $field = $field->{$method}(...$arguments);
            }

            return $field;
        }, self::specs($request));
    }

    /**
     * The fields, as what to build: a component class, its name and the
     * setters to call.
     *
     * @param  class-string<FormRequest>                                                                                                   $request
     * @return list<array{component: class-string<Field>, name: string, calls: list<array{0: string, 1: list<mixed>}>, note: string|null}>
     */
    public static function specs(string $request): array
    {
        [$rules, $labels] = self::read($request);

        $byField = [];

        foreach ($rules as $name => $fieldRules) {
            $byField[(string) $name] = self::tokens($fieldRules);
        }

        $specs = [];

        foreach ($byField as $name => $tokens) {
            if (str_contains($name, '.') || isset($tokens['exclude']) || isset($tokens['prohibited']) || isset($tokens['missing'])) {
                continue;
            }

            $spec = self::spec($name, $tokens, $byField[$name.'.*'] ?? []);

            if (isset($labels[$name]) && is_string($labels[$name])) {
                $spec['calls'][] = ['label', [$labels[$name]]];
            }

            $specs[] = $spec;

            if (isset($tokens['confirmed'])) {
                $confirmation          = $spec;
                $confirmation['name']  = $name.'_confirmation';
                $confirmation['note']  = null;
                $confirmation['calls'] = array_values(array_filter(
                    $spec['calls'],
                    static fn (array $call): bool => $call[0] !== 'label',
                ));

                if (isset($labels[$name]) && is_string($labels[$name])) {
                    $confirmation['calls'][] = ['label', [$labels[$name].' confirmation']];
                }

                $specs[] = $confirmation;
            }
        }

        return $specs;
    }

    /**
     * @param  array<string, list<string>>                                                                                           $tokens
     * @param  array<string, list<string>>                                                                                           $each   the rules of `name.*`
     * @return array{component: class-string<Field>, name: string, calls: list<array{0: string, 1: list<mixed>}>, note: string|null}
     */
    private static function spec(string $name, array $tokens, array $each): array
    {
        $calls = [];
        $note  = null;
        $has   = static fn (string ...$rules): bool => array_intersect($rules, array_keys($tokens)) !== [];

        if ($has('file', 'image', 'mimes', 'mimetypes') || $each !== [] && array_intersect(['file', 'image', 'mimes', 'mimetypes'], array_keys($each)) !== []) {
            $component = FileUpload::class;

            if ($has('image') || isset($each['image'])) {
                $calls[] = ['image', []];
            }

            if ($has('array')) {
                $calls[] = ['multiple', []];
            }
        } elseif ($has('array')) {
            $options = self::options($each);

            if ($options !== null) {
                $component = CheckboxList::class;
                $calls[]   = ['options', [$options]];
            } else {
                $component = TagsInput::class;
            }
        } elseif ($has('boolean')) {
            $component = Toggle::class;
        } elseif ($has('accepted')) {
            $component = Checkbox::class;
        } elseif (($options = self::options($tokens)) !== null) {
            $component = Select::class;
            $calls[]   = ['options', [$options]];
        } elseif ($has('date', 'date_format', 'before', 'after', 'before_or_equal', 'after_or_equal')) {
            $format    = $tokens['date_format'][0] ?? '';
            $component = preg_match('/[GgHhis]/', $format) === 1 || str_ends_with($name, '_at')
                ? DateTimePicker::class
                : DatePicker::class;
        } elseif ($has('integer', 'numeric', 'decimal')) {
            $component = NumberField::class;

            if (isset($tokens['min'][0]) && is_numeric($tokens['min'][0])) {
                $calls[] = ['min', [$tokens['min'][0] + 0]];
            }

            if (isset($tokens['max'][0]) && is_numeric($tokens['max'][0])) {
                $calls[] = ['max', [$tokens['max'][0] + 0]];
            }
        } else {
            $max       = isset($tokens['max'][0]) && is_numeric($tokens['max'][0]) ? (int) $tokens['max'][0] : null;
            $component = $max !== null            && $max > self::TEXTAREA_OVER ? Textarea::class : TextInput::class;

            if ($component === TextInput::class) {
                if ($has('password', 'current_password') || str_contains($name, 'password')) {
                    $calls[] = ['password', []];
                } elseif ($has('email')) {
                    $calls[] = ['email', []];
                } elseif ($has('url', 'active_url')) {
                    $calls[] = ['url', []];
                }
            }

            if ($max !== null) {
                $calls[] = ['maxLength', [$max]];
            }

            if (isset($tokens['min'][0]) && is_numeric($tokens['min'][0])) {
                $calls[] = ['minLength', [(int) $tokens['min'][0]]];
            }

            if (isset($tokens['exists'])) {
                $note = 'exists:'.implode(',', $tokens['exists']).' — a relation id: consider a Select with ->relationship()';
            }
        }

        if ($has('required') && ! $has('sometimes')) {
            array_unshift($calls, ['required', []]);
        }

        return ['component' => $component, 'name' => $name, 'calls' => $calls, 'note' => $note];
    }

    /**
     * The choices `in:` or `Rule::enum()` allows: an enum class, or value =>
     * label.
     *
     * @param  array<string, list<string>>       $tokens
     * @return array<string, string>|string|null
     */
    private static function options(array $tokens): array|string|null
    {
        if (isset($tokens['enum'][0])) {
            return $tokens['enum'][0];
        }

        if (! isset($tokens['in'])) {
            return null;
        }

        $options = [];

        foreach ($tokens['in'] as $value) {
            $options[$value] = Str::headline($value);
        }

        return $options;
    }

    /**
     * A field's rules as name => parameters. Rule objects that print as a
     * rule string (`Rule::in()`, `Rule::exists()`) are read from it; an
     * enum, file or password rule by its kind; anything else (a closure, a
     * custom rule) says nothing about the input.
     *
     * @return array<string, list<string>>
     */
    private static function tokens(mixed $rules): array
    {
        $tokens = [];

        foreach (is_string($rules) ? explode('|', $rules) : (array) $rules as $rule) {
            if ($rule instanceof Enum) {
                $tokens['enum'] = [(string) (new ReflectionProperty($rule, 'type'))->getValue($rule)];

                continue;
            }

            if ($rule instanceof ImageFile) {
                $tokens['image'] = [];

                continue;
            }

            if ($rule instanceof File) {
                $tokens['file'] = [];

                continue;
            }

            if ($rule instanceof Password) {
                $tokens['password'] = [];

                continue;
            }

            if ($rule instanceof Stringable) {
                $rule = (string) $rule;
            }

            if (! is_string($rule) || $rule === '') {
                continue;
            }

            [$ruleName, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
            $ruleName                = strtolower(trim($ruleName));

            $tokens[$ruleName] = $parameters === null
                ? []
                : array_map(static fn (?string $value): string => (string) $value, str_getcsv($parameters, ',', '"', ''));
        }

        return $tokens;
    }

    /**
     * The request's rules and attribute labels.
     *
     * @param  class-string<FormRequest>                                     $request
     * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>}
     */
    private static function read(string $request): array
    {
        if (! is_subclass_of($request, FormRequest::class)) {
            throw new InvalidArgumentException("[{$request}] is not a FormRequest.");
        }

        try {
            $instance = $request::createFrom(request());
            $instance->setContainer(app());

            $rules = method_exists($instance, 'rules') ? app()->call([$instance, 'rules']) : [];

            return [(array) $rules, $instance->attributes()];
        } catch (Throwable $e) {
            throw new LogicException("The rules of [{$request}] can't be read outside its route ({$e->getMessage()}). Give the form a Form class instead.", 0, $e);
        }
    }
}
