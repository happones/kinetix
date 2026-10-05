<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `kinetix.motion` reaches the client as `kinetix_config.motion`, where
 * `useKinetixReducedMotion` stills the transition presets and auto-rotation
 * for everyone when it says `reduced`.
 */
class MotionShareTest extends TestCase
{
    public function test_motion_is_full_by_default(): void
    {
        $this->assertSame('full', $this->sharedMotion());
    }

    public function test_reduced_motion_is_shared_as_is(): void
    {
        config()->set('kinetix.motion', 'reduced');

        $this->assertSame('reduced', $this->sharedMotion());
    }

    /**
     * Anything but `reduced` — a typo, an empty env var — means full motion,
     * so the client only ever sees one of the two documented values.
     */
    #[DataProvider('unrecognizedValues')]
    public function test_an_unrecognized_value_falls_back_to_full(mixed $value): void
    {
        config()->set('kinetix.motion', $value);

        $this->assertSame('full', $this->sharedMotion());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unrecognizedValues(): array
    {
        return [
            'typo'  => ['reduce'],
            'empty' => [''],
            'null'  => [null],
        ];
    }

    private function sharedMotion(): mixed
    {
        $shared = Inertia::getShared('kinetix_config');

        /** @var array{motion: string} $resolved */
        $resolved = is_callable($shared) ? $shared() : $shared;

        return $resolved['motion'];
    }
}
