<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\FormRequestFields;

class MakeFormCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-form {name : The form class name (e.g. ProfileForm)}
        {--from-request= : A FormRequest whose rules decide the fields (e.g. "App\\Http\\Requests\\StorePostRequest")}
        {--force}';

    protected $description = 'Create a reusable Kinetix Form class';

    protected function subNamespace(): string
    {
        return 'Forms';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();
        $request   = $this->option('from-request');

        [$imports, $fields] = is_string($request) && $request !== ''
            ? $this->fieldsFromRequest(ltrim($request, '\\'))
            : [[TextInput::class], ["TextInput::make('name')->required(),"]];

        // Ordered as Pint orders them (case-insensitive).
        $imports = array_values(array_unique([...$imports, Form::class]));
        usort($imports, strcasecmp(...));

        $uses = implode("\n", array_map(static fn (string $import): string => "use {$import};", $imports));

        $body = implode("\n", array_map(static fn (string $line): string => '            '.$line, $fields));

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        {$uses}

        class {$class} extends Form
        {
            protected function buildSchema(): array
            {
                return [
        {$body}
                ];
            }
        }
        PHP;
    }

    /**
     * The imports and field lines the request's rules give
     * ({@see FormRequestFields}), a note above any field worth a second look.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function fieldsFromRequest(string $request): array
    {
        $imports = [];
        $lines   = [];

        foreach (FormRequestFields::specs($request) as $spec) {
            $imports[] = $spec['component'];
            $line      = class_basename($spec['component'])."::make('{$spec['name']}')";

            foreach ($spec['calls'] as [$method, $arguments]) {
                $line .= '->'.$method.'('.implode(', ', array_map(function (mixed $argument) use (&$imports): string {
                    if (is_string($argument) && enum_exists($argument)) {
                        $imports[] = $argument;

                        return class_basename($argument).'::class';
                    }

                    return $this->export($argument);
                }, $arguments)).')';
            }

            if ($spec['note'] !== null) {
                $lines[] = '// '.$spec['note'];
            }

            $lines[] = $line.',';
        }

        return [$imports, $lines];
    }

    /**
     * A value as short PHP source.
     */
    private function export(mixed $value): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = array_is_list($value) ? $this->export($item) : var_export($key, true).' => '.$this->export($item);
        }

        return '['.implode(', ', $items).']';
    }
}
