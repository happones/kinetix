<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

/**
 * The parts of an address value and their translation keys (`kinetix.*`).
 */
final class AddressParts
{
    public const FIELDS = ['line1', 'line2', 'city', 'state', 'postalCode', 'country'];

    public const LABELS = [
        'line1'      => 'address_line1',
        'line2'      => 'address_line2',
        'city'       => 'address_city',
        'state'      => 'address_state',
        'postalCode' => 'address_postal',
        'country'    => 'address_country',
    ];
}
