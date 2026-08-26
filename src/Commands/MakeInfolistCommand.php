<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeInfolistCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-infolist {name : The infolist class name (e.g. UserInfolist)} {--force}';

    protected $description = 'Create a reusable Kinetix Infolist class';

    protected function subNamespace(): string
    {
        return 'Infolists';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Infolists\\Components\\TextEntry;
        use Happones\\Kinetix\\Infolists\\Infolist;

        class {$class} extends Infolist
        {
            protected function buildSchema(): array
            {
                return [
                    TextEntry::make('name'),
                ];
            }
        }
        PHP;
    }
}
