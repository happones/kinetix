<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Inertia\Inertia;

/**
 * `kinetix.lightbox.markdown` reaches the client as
 * `kinetix_config.lightbox.markdown`, where KinetixMarkdownContent decides
 * whether Help Center / Docs images open in the lightbox.
 */
class LightboxShareTest extends TestCase
{
    public function test_markdown_images_open_in_the_lightbox_by_default(): void
    {
        $this->assertTrue($this->sharedLightbox()['markdown']);
    }

    public function test_the_app_can_turn_markdown_images_off(): void
    {
        config()->set('kinetix.lightbox.markdown', false);

        $this->assertFalse($this->sharedLightbox()['markdown']);
    }

    /**
     * @return array{markdown: bool}
     */
    private function sharedLightbox(): array
    {
        $shared = Inertia::getShared('kinetix_config');

        /** @var array{lightbox: array{markdown: bool}} $resolved */
        $resolved = is_callable($shared) ? $shared() : $shared;

        return $resolved['lightbox'];
    }
}
