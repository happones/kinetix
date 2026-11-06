<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tests\TestCase;

class CopyableTest extends TestCase
{
    public function test_text_input_serializes_copyable_and_revealable(): void
    {
        $data = TextInput::make('api_key')->copyable()->revealable()->toData('create');

        $this->assertTrue($data->isCopyable);
        $this->assertTrue($data->isRevealable);

        $default = TextInput::make('name')->toData('create');

        $this->assertFalse($default->isCopyable);
        $this->assertFalse($default->isRevealable);
    }

    public function test_text_column_is_copyable(): void
    {
        $this->assertTrue(TextColumn::make('token')->copyable()->toData()->isCopyable);
        $this->assertNull(TextColumn::make('plain')->toData()->isCopyable);
    }
}
