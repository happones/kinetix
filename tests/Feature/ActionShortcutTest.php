<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tests\TestCase;

class ActionShortcutTest extends TestCase
{
    public function test_shortcut_is_serialized(): void
    {
        $data = Action::make('create')->shortcut('c')->toData();

        $this->assertSame('c', $data->shortcut);
    }

    public function test_shortcut_defaults_to_null(): void
    {
        $data = Action::make('edit')->toData();

        $this->assertNull($data->shortcut);
    }

    public function test_icon_button_is_serialized_and_defaults_off(): void
    {
        $this->assertTrue(Action::make('edit')->icon('edit')->iconButton()->toData()->isIconButton);
        $this->assertFalse(Action::make('edit')->toData()->isIconButton);
    }
}
