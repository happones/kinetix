<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\Fieldset;
use Happones\Kinetix\Forms\Components\Placeholder;
use Happones\Kinetix\Forms\Components\Split;
use Happones\Kinetix\Forms\Components\Tab;
use Happones\Kinetix\Forms\Components\Tabs;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;

class FormLayoutTest extends TestCase
{
    public function test_fieldset_serializes_with_heading_and_nested_schema(): void
    {
        $data = Fieldset::make('Address')
            ->columns(6)
            ->schema([TextInput::make('city')])
            ->toData('create', null);

        $this->assertNotNull($data);
        $this->assertSame('fieldset', $data->type);
        $this->assertSame('Address', $data->heading);
        $this->assertSame(6, $data->columns);
        $this->assertCount(1, $data->schema);
        $this->assertSame('city', $data->schema[0]->name);
    }

    public function test_tabs_serialize_each_tab_with_icon_and_children(): void
    {
        $data = Tabs::make()->tabs([
            Tab::make('Profile')->schema([TextInput::make('name')]),
            Tab::make('Security')->icon('settings')->schema([TextInput::make('password')]),
        ])->toData('create', null);

        $this->assertNotNull($data);
        $this->assertSame('tabs', $data->type);
        $this->assertCount(2, $data->schema);
        $this->assertSame('tab', $data->schema[0]->type);
        $this->assertSame('Profile', $data->schema[0]->heading);
        $this->assertSame('settings', $data->schema[1]->icon);
        $this->assertSame('password', $data->schema[1]->schema[0]->name);
    }

    public function test_split_serializes_children(): void
    {
        $data = Split::make([
            TextInput::make('first'),
            TextInput::make('last'),
        ])->toData('create', null);

        $this->assertNotNull($data);
        $this->assertSame('split', $data->type);
        $this->assertCount(2, $data->schema);
    }

    public function test_placeholder_renders_label_and_resolved_content(): void
    {
        $data = Placeholder::make('Status')
            ->content(fn () => 'Active')
            ->toData('create', null);

        $this->assertNotNull($data);
        $this->assertSame('placeholder', $data->type);
        $this->assertSame('Status', $data->label);
        $this->assertSame('Active', $data->content);
    }

    public function test_fields_inside_layout_components_are_extracted_for_validation(): void
    {
        $form = Form::make()->schema([
            Tabs::make()->tabs([
                Tab::make('One')->schema([
                    Fieldset::make('Group')->schema([
                        TextInput::make('email')->required(),
                    ]),
                ]),
                Tab::make('Two')->schema([
                    Split::make([TextInput::make('phone')->required()]),
                ]),
            ]),
        ]);

        $rules = $form->getValidationRules();

        // Nested fields across tabs/fieldset/split are all discovered.
        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('phone', $rules);
    }

    public function test_placeholder_is_not_treated_as_a_field(): void
    {
        $form = Form::make()->schema([
            Placeholder::make('Info')->content('read only'),
            TextInput::make('title')->required(),
        ]);

        $rules = $form->getValidationRules();

        $this->assertArrayHasKey('title', $rules);
        $this->assertArrayNotHasKey('Info', $rules);
    }

    public function test_hidden_layout_returns_null(): void
    {
        $data = Fieldset::make('Hidden')
            ->hidden()
            ->schema([TextInput::make('x')])
            ->toData('create', null);

        $this->assertNull($data);
    }
}
