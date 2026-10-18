<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

/** Who a message is from: the contact (`In`), the team (`Out`), or the team to itself (`Note`, never sent). */
enum MessageDirection: string
{
    case In   = 'in';
    case Out  = 'out';
    case Note = 'note';
}
