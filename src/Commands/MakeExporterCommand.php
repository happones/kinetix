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

        use App\\Models\\Model;
        use Happones\\Kinetix\\Exports\\ExportColumn;
        use Happones\\Kinetix\\Exports\\Exporter;

        class {$class} extends Exporter
        {
            protected static ?string \$model = Model::class;

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
