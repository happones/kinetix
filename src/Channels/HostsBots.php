<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Happones\Kinetix\Channels\Evolution\WhatsAppBot;

/**
 * A provider that runs a bot of its own on a line (WhatsApp through an
 * Evolution integration). Kinetix Flows leave such lines to it.
 */
interface HostsBots
{
    /** Put the bot on the line, replacing the one it had. The caller saves the line. */
    public function attachBot(Channel $channel, WhatsAppBot $bot): void;

    /** Take the line's bot off. The caller saves the line. */
    public function detachBot(Channel $channel): void;
}
