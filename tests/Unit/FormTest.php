<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\FileUpload;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class FormTest extends TestCase
{
    public function test_validation_rules_are_compiled(): void
    {
        $form = Form::make()->schema([
            TextInput::make('title')->required()->maxLength(5),
        ]);

        $this->assertSame(
            ['title' => ['required', 'max:5']],
            $form->getValidationRules()
        );
    }

    public function test_valid_input_returns_state(): void
    {
        $form = Form::make()->schema([
            TextInput::make('title')->required(),
        ]);

        $this->assertSame(['title' => 'hello'], $form->getState(['title' => 'hello']));
    }

    public function test_invalid_input_throws(): void
    {
        $form = Form::make()->schema([
            TextInput::make('title')->required(),
        ]);

        $this->expectException(ValidationException::class);
        $form->validate([]);
    }

    public function test_field_validation_messages_are_namespaced_and_attributes_default_to_labels(): void
    {
        $form = Form::make()->schema([
            TextInput::make('email')
                ->label('Email Address')
                ->required()
                ->validationMessages(['required' => 'We need your email.']),
        ]);

        $this->assertSame(
            ['email.required' => 'We need your email.'],
            $form->getValidationMessages()
        );

        $this->assertSame(
            ['email' => 'Email Address'],
            $form->getValidationAttributes()
        );
    }

    public function test_form_level_messages_and_attributes_win(): void
    {
        $form = Form::make()
            ->schema([
                TextInput::make('email')
                    ->label('Email Address')
                    ->required()
                    ->validationMessages(['required' => 'field level']),
            ])
            ->messages(['email.required' => 'form level'])
            ->validationAttributes(['email' => 'E-mail']);

        $this->assertSame(['email.required' => 'form level'], $form->getValidationMessages());
        $this->assertSame(['email' => 'E-mail'], $form->getValidationAttributes());
    }

    public function test_validation_uses_custom_message(): void
    {
        $form = Form::make()->schema([
            TextInput::make('email')
                ->required()
                ->validationMessages(['required' => 'We need your email.']),
        ]);

        try {
            $form->validate([]);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('We need your email.', $e->validator->errors()->first('email'));
        }
    }

    public function test_precognitive_config_is_serialized(): void
    {
        $form = Form::make()
            ->schema([TextInput::make('title')])
            ->validationUrl('/posts/validate', 'put');

        $data = $form->toArray();

        $this->assertTrue($data['precognitive']);
        $this->assertSame('/posts/validate', $data['validationUrl']);
        $this->assertSame('put', $data['validationMethod']);
    }

    public function test_precognitive_defaults_off(): void
    {
        $data = Form::make()->schema([TextInput::make('title')])->toArray();

        $this->assertFalse($data['precognitive']);
        $this->assertNull($data['validationUrl']);
    }

    public function test_file_upload_signs_storage_config(): void
    {
        $data = FileUpload::make('avatar')
            ->image()
            ->disk('public')
            ->directory('avatars')
            ->maxSize(1024)
            ->toData('create', null);

        $this->assertSame('file-upload', $data->type);
        $this->assertTrue($data->isImage);

        $config = Crypt::decrypt($data->uploadToken);
        $this->assertSame('public', $config['disk']);
        $this->assertSame('avatars', $config['directory']);
        $this->assertTrue($config['image']);
    }
}
