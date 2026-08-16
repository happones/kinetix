<?php

declare(strict_types=1);

namespace Happones\Kinetix\Spotlight;

/**
 * Opt-in ordering for a spotlight source's group.
 *
 * Deliberately NOT part of `SpotlightSource`: hosts implement that interface
 * directly, and adding a method to it would fatal every custom source on
 * upgrade. A source that doesn't implement this one is treated as priority 0.
 */
interface HasSpotlightPriority
{
    /**
     * Higher sorts first. Sources sharing a group resolve to the highest
     * priority among them; equal priorities keep registration order.
     */
    public function getPriority(): int;
}
