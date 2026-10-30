<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locations;

use Happones\Kinetix\Forms\Components\AddressPicker;

/**
 * The parts of an address value and their translation keys (`kinetix.*`).
 */
final class AddressParts
{
    /**
     * Every part a picker can show. `neighborhood` (a colonia, a barrio) is
     * opt-in: AddressPicker shows {@see AddressPicker::FIELDS}
     * unless told otherwise.
     */
    public const FIELDS = ['line1', 'line2', 'neighborhood', 'city', 'state', 'postalCode', 'country'];

    public const LABELS = [
        'line1'        => 'address_line1',
        'line2'        => 'address_line2',
        'neighborhood' => 'address_neighborhood',
        'city'         => 'address_city',
        'state'        => 'address_state',
        'postalCode'   => 'address_postal',
        'country'      => 'address_country',
    ];
}
