<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Why a request may not use a signed descriptor ({@see SignedDescriptor::rejection()}).
 */
enum DescriptorRejection
{
    /** Minted for a different user — a token lifted from someone else's page. */
    case ForeignUser;

    /** Minted in a different team than the one the request is routed to. */
    case ForeignTeam;

    /** Past its expiry, or minted before descriptors carried their binding claims. */
    case Expired;
}
