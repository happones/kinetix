<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeRelationManagerCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-relation-manager {name : The relation manager class name (e.g. PostsRelationManager)} {--relationship= : The parent relationship method name} {--force}';

    protected $description = 'Create a Kinetix Relation Manager class';

    protected function subNamespace(): string
    {
        return 'RelationManagers';
    }

    protected function stub(string $class): string
    {
        $namespace    = $this->namespace();
        $relationship = $this->option('relationship') ?: 'items';

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Resources\\RelationManager;
        use Happones\\Kinetix\\Tables\\Table;
        use Happones\\Kinetix\\Tables\\Columns\\TextColumn;

        class {$class} extends RelationManager
        {
            protected static string \$relationship = '{$relationship}';

            // Pages this manager appears on. Restrict with e.g. ['view'] or ['edit'].
            protected static array \$visibleOn = ['edit', 'view'];

            public function table(Table \$table): Table
            {
                return \$table->columns([
                    TextColumn::make('id')->sortable(),
                ]);
            }
        }
        PHP;
    }
}
