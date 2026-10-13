<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class FormDefaultsRecord extends Model
{
    protected $guarded = [];
}

/**
 * A form rendered without fill() — a create form, an action's modal — opened
 * with every field empty, its defaults ignored.
 */
class FormDefaultsTest extends TestCase
{
    public function test_a_form_never_filled_opens_with_its_defaults(): void
    {
        $data = Form::make()->schema([
            TextInput::make('currency')->default('MXN'),
            Toggle::make('active')->default(true),
            TextInput::make('note'),
        ])->toArray()['data'];

        $this->assertSame(['currency' => 'MXN', 'active' => true, 'note' => null], $data);
    }

    public function test_a_form_built_on_a_record_takes_its_defaults_not_the_records_values(): void
    {
        $record = new FormDefaultsRecord(['reason' => 'stored']);

        $data = Form::make($record)->schema([TextInput::make('reason')->default('none')])->toArray()['data'];

        $this->assertSame(['reason' => 'none'], $data);
    }

    public function test_a_filled_form_keeps_what_it_was_filled_with(): void
    {
        $data = Form::make()->schema([TextInput::make('currency')->default('MXN')])
            ->fill(['currency' => 'USD'])
            ->toArray()['data'];

        $this->assertSame(['currency' => 'USD'], $data);
    }
}
