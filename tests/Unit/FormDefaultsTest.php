<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\Checkbox;
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

    public function test_an_untouched_toggle_or_checkbox_submits_off_not_empty(): void
    {
        $data = Form::make()->schema([
            Toggle::make('is_featured'),
            Checkbox::make('terms'),
            Toggle::make('active')->default(true),
        ])->toArray()['data'];

        $this->assertSame(['is_featured' => false, 'terms' => false, 'active' => true], $data);

        $record = new FormDefaultsRecord(['is_featured' => null]);

        $edited = Form::make()->schema([Toggle::make('is_featured')])->fill($record)->toArray()['data'];

        $this->assertSame(['is_featured' => false], $edited);
    }
}
