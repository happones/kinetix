<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Tests\TestCase;

class FieldHelperTextTest extends TestCase
{
    public function test_helper_text_serializes_as_the_fields_description(): void
    {
        $data = TextInput::make('email')
            ->helperText('We never share it.')
            ->toData('create');

        $this->assertSame('We never share it.', $data->description);
    }

    public function test_a_closure_helper_text_resolves_and_absence_stays_null(): void
    {
        $closure = TextInput::make('email')
            ->helperText(fn (): string => 'Computed hint')
            ->toData('create');

        $this->assertSame('Computed hint', $closure->description);

        $this->assertNull(TextInput::make('email')->toData('create')->description);
    }
}
