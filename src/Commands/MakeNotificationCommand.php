<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:make-notification {name : The name of the notification class}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Kinetix notification class';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        // Ensure name is StudlyCase
        $name = ucfirst(Str::camel($name));

        $directory = app_path('Kinetix/Notifications');
        $filePath  = "{$directory}/{$name}.php";

        if (File::exists($filePath)) {
            $this->error("Notification class {$name} already exists!");

            return self::FAILURE;
        }

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Kinetix\Notifications;

use Happones\Kinetix\Notifications\Notification;

class {$name} extends Notification
{
    public function __construct()
    {
        parent::__construct();
        
        // Configure your notification defaults here
        \$this->title('{$name} Notification')
             ->description('This is a custom notification created by Kinetix.')
             ->info()
             ->duration(4000);
    }
}
PHP;

        File::put($filePath, $template);
        $this->info("Kinetix notification class [app/Kinetix/Notifications/{$name}.php] created successfully!");

        return self::SUCCESS;
    }
}
