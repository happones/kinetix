<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeReportCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-report {name : The report class name (e.g. MonthlyInvoicesReport)} {--force}';

    protected $description = 'Create a Kinetix Report class (auto-discovered by the Reports Center)';

    protected function subNamespace(): string
    {
        return 'Reports';
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
        use Happones\\Kinetix\\ReportsCenter\\Report;

        class {$class} extends Report
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
