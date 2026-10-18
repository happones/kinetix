<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Channels\ChannelManager;
use Happones\Kinetix\Channels\ChannelStatus;
use Illuminate\Console\Command;

/**
 * Check every linked (or linking) line against its provider, so a line
 * logged out from the phone — or a webhook that never arrived — shows its
 * real status. Schedule it every few minutes.
 */
class ChannelsSyncCommand extends Command
{
    protected $signature = 'kinetix:channels:sync';

    protected $description = 'Check every Kinetix line against its provider and record its real status';

    public function handle(ChannelManager $manager): int
    {
        $checked = 0;

        Channel::query()
            ->whereIn('status', [ChannelStatus::Connected->value, ChannelStatus::Connecting->value])
            ->lazyById()
            ->each(function (Channel $channel) use ($manager, &$checked): void {
                if ($manager->has($channel->provider)) {
                    $manager->sync($channel, force: true);
                    $checked++;
                }
            });

        $this->info("Checked {$checked} line".($checked === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
