<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeExporterCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-exporter {name : The exporter class name (e.g. ContactExporter)} {--force}';

    protected $description = 'Create a Kinetix Exporter class';

    protected function subNamespace(): string
    {
        return 'Exporters';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Exports\\Exporter;
        use Happones\\Kinetix\\Exports\\ExportColumn;

        class {$class} extends Exporter
        {
            protected static ?string \$model = \\App\\Models\\Model::class;

            public function format(): string
            {
                return 'csv';
            }

            public static function getColumns(): array
            {
                return [
                    ExportColumn::make('id'),
                    ExportColumn::make('name'),
                ];
            }
        }
        PHP;
    }
}
