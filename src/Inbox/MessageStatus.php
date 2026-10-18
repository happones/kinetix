<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

/**
 * Where a message stands: one the contact sent (`Received`), or one the team
 * sent — on its way (`Pending`), accepted by the provider (`Sent`), on the
 * contact's phone (`Delivered`), seen (`Read`) or refused (`Failed`).
 */
enum MessageStatus: string
{
    case Received  = 'received';
    case Pending   = 'pending';
    case Sent      = 'sent';
    case Delivered = 'delivered';
    case Read      = 'read';
    case Failed    = 'failed';

    /** How far along it is: receipts arrive out of order and never move a message back. */
    public function rank(): int
    {
        return match ($this) {
            self::Received, self::Pending, self::Failed => 0,
            self::Sent                                  => 1,
            self::Delivered                             => 2,
            self::Read                                  => 3,
        };
    }
}
