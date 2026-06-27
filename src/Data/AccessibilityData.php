<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AccessibilityData extends Data
{
    public function __construct(
        public bool $reducedMotion = false,
        public bool $highContrast = false,
        public string $textSize = 'normal',
        public bool $underlineLinks = false,
        public bool $enhancedFocus = false,
    ) {}

    /**
     * @param array<string, mixed> $prefs
     */
    public static function fromArray(array $prefs): self
    {
        return new self(
            reducedMotion: (bool) ($prefs['reducedMotion'] ?? false),
            highContrast: (bool) ($prefs['highContrast'] ?? false),
            textSize: in_array($prefs['textSize'] ?? 'normal', ['normal', 'large', 'x-large'], true)
                ? (string) $prefs['textSize']
                : 'normal',
            underlineLinks: (bool) ($prefs['underlineLinks'] ?? false),
            enhancedFocus: (bool) ($prefs['enhancedFocus'] ?? false),
        );
    }
}
