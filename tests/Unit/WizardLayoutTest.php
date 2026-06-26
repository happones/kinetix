<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\Step;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Wizard;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;

class WizardLayoutTest extends TestCase
{
    public function test_wizard_serializes_steps_variant_and_slug(): void
    {
        $data = Wizard::make()
            ->variant('gradient')
            ->slug('account-setup')
            ->steps([
                Step::make('Account')->schema([TextInput::make('email')]),
                Step::make('Profile')->icon('user')->description('About you')->schema([
                    TextInput::make('name'),
                ]),
            ])
            ->toData('create', null);

        $this->assertNotNull($data);
        $this->assertSame('wizard', $data->type);
        $this->assertSame('gradient', $data->variant);
        $this->assertSame('account-setup', $data->slug);
        $this->assertCount(2, $data->schema);
        $this->assertSame('wizard-step', $data->schema[0]->type);
        $this->assertSame('Account', $data->schema[0]->heading);
        $this->assertSame('user', $data->schema[1]->icon);
        $this->assertSame('About you', $data->schema[1]->description);
    }

    public function test_required_flag_is_exposed_for_step_gating(): void
    {
        $data = TextInput::make('email')->required()->toData('create', null);

        $this->assertTrue($data->isRequired);

        $optional = TextInput::make('nickname')->toData('create', null);
        $this->assertFalse($optional->isRequired);
    }

    public function test_fields_across_wizard_steps_are_extracted_for_validation(): void
    {
        $form = Form::make()->schema([
            Wizard::make()->steps([
                Step::make('One')->schema([TextInput::make('email')->required()]),
                Step::make('Two')->schema([TextInput::make('name')->required()]),
            ]),
        ]);

        $rules = $form->getValidationRules();

        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('name', $rules);
    }

    public function test_empty_wizard_serializes_to_null(): void
    {
        $this->assertNull(Wizard::make()->steps([])->toData('create', null));
    }
}
