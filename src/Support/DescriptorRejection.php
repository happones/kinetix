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

    /** Minted for a different endpoint family ({@see DescriptorAudience}). */
    case ForeignAudience;

    /**
     * The machine code a rejection answers with (`code` in the JSON), so a
     * client can tell "reload the page" from "not allowed" without matching
     * a translated message.
     *
     * @return 'descriptor_expired'|'descriptor_forbidden'
     */
    public function code(): string
    {
        return $this === self::Expired ? 'descriptor_expired' : 'descriptor_forbidden';
    }
}
