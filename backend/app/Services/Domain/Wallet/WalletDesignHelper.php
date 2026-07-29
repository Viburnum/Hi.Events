<?php

namespace HiEvents\Services\Domain\Wallet;

use HiEvents\DomainObjects\EventDomainObject;

class WalletDesignHelper
{
    /**
     * Returns the event's ticket accent colour as a #RRGGBB string, or null when unset/invalid.
     */
    public static function accentHex(EventDomainObject $event): ?string
    {
        $designSettings = $event->getEventSettings()?->getTicketDesignSettings();

        if (is_string($designSettings)) {
            $designSettings = json_decode($designSettings, true);
        }

        $color = is_array($designSettings) ? ($designSettings['accent_color'] ?? null) : null;

        if (! is_string($color) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return $color;
    }

    /**
     * Converts a #RRGGBB string to Apple's "rgb(r, g, b)" colour format.
     */
    public static function hexToRgbString(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return sprintf('rgb(%d, %d, %d)', $r, $g, $b);
    }
}
