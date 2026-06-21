<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeActionCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-action {name : The action class name (e.g. ApproveAction)} {--force}';

    protected $description = 'Create a reusable Kinetix Action class';

    protected function subNamespace(): string
    {
        return 'Actions';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Actions\\Action;

        class {$class} extends Action
        {
            public function __construct(string \$name = 'action')
            {
                parent::__construct(\$name);

                \$this->label('Action')
                    ->icon('check')
                    ->color('primary');
            }
        }
        PHP;
    }
}
