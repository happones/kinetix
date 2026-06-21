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
