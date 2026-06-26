<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeSettingsPageCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-settings-page {name : The settings page class name (e.g. GeneralSettingsPage)} {--force}';

    protected $description = 'Create a Kinetix database-backed settings page';

    protected function subNamespace(): string
    {
        return 'Settings';
    }

    protected function stub(string $class): string
    {
        $namespace = $this->namespace();

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Forms\\Components\\TextInput;
        use Happones\\Kinetix\\Forms\\Components\\Toggle;
        use Happones\\Kinetix\\Settings\\SettingsPage;

        class {$class} extends SettingsPage
        {
            public function schema(): array
            {
                return [
                    TextInput::make('site_name')->required(),
                    Toggle::make('maintenance_mode'),
                ];
            }
        }
        PHP;
    }
}
