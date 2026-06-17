<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Notifications\Notification;
use Illuminate\Console\Command;

class SendNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kinetix:send-notification 
                            {title : The title of the notification}
                            {description? : The description of the notification}
                            {--status=info : The status of the notification (info, success, warning, danger)}
                            {--duration=4000 : The duration of the notification in milliseconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a test Kinetix notification via session';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title       = $this->argument('title');
        $description = $this->argument('description');
        $status      = $this->option('status');
        $duration    = (int) $this->option('duration');

        Notification::make()
            ->title($title)
            ->description($description)
            ->status($status)
            ->duration($duration)
            ->send();

        $this->info("Kinetix notification dispatched successfully: '{$title}' with status '{$status}'");

        return self::SUCCESS;
    }
}
