<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tests\TestCase;

class ActionShortcutTest extends TestCase
{
    public function test_shortcut_is_serialized(): void
    {
        $this->assertSame('c', Action::make('create')->shortcut('c')->toData()->shortcut);
        $this->assertNull(Action::make('edit')->toData()->shortcut);
    }

    public function test_icon_button_is_serialized_and_defaults_off(): void
    {
        $this->assertTrue(Action::make('edit')->icon('edit')->iconButton()->toData()->isIconButton);
        $this->assertFalse(Action::make('edit')->toData()->isIconButton);
    }
}
