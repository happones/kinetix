<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeTableCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-table {name : The table class name (e.g. UsersTable)} {--force}';

    protected $description = 'Create a reusable Kinetix Table class';

    protected function subNamespace(): string
    {
        return 'Tables';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Tables\\Columns\\TextColumn;
        use Happones\\Kinetix\\Tables\\Table;

        class {$class} extends Table
        {
            protected function buildColumns(): array
            {
                return [
                    TextColumn::make('id')->sortable(),
                ];
            }

            protected function buildFilters(): array
            {
                return [];
            }

            protected function buildRecordActions(): array
            {
                return [];
            }

            protected function buildToolbarActions(): array
            {
                return [];
            }
        }
        PHP;
    }
}
