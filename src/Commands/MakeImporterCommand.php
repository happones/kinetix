<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeImporterCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-importer {name : The importer class name (e.g. ContactImporter)} {--force}';

    protected $description = 'Create a Kinetix Importer class';

    protected function subNamespace(): string
    {
        return 'Importers';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Imports\\Importer;
        use Happones\\Kinetix\\Imports\\ImportColumn;

        class {$class} extends Importer
        {
            protected static ?string \$model = \\App\\Models\\Model::class;

            public static function getColumns(): array
            {
                return [
                    ImportColumn::make('name')->requiredMapping(),
                ];
            }
        }
        PHP;
    }
}
