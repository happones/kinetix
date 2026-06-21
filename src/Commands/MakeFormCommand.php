<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeFormCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-form {name : The form class name (e.g. ProfileForm)} {--force}';

    protected $description = 'Create a reusable Kinetix Form class';

    protected function subNamespace(): string
    {
        return 'Forms';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Forms\\Form;
        use Happones\\Kinetix\\Forms\\Components\\TextInput;

        class {$class} extends Form
        {
            protected function buildSchema(): array
            {
                return [
                    TextInput::make('name')->required(),
                ];
            }
        }
        PHP;
    }
}
