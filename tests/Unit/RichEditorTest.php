<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\RichEditor;
use Happones\Kinetix\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RichEditorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function configuredDrivers(): array
    {
        return [
            'basic'    => ['basic'],
            'tiptap'   => ['tiptap'],
            'markdown' => ['markdown'],
        ];
    }

    #[DataProvider('configuredDrivers')]
    public function test_serializes_with_type_and_config_default_driver(string $driver): void
    {
        config()->set('kinetix.forms.rich_editor', $driver);

        $data = RichEditor::make('body')->toData('create', null);

        $this->assertSame('rich-editor', $data->type);
        $this->assertSame($driver, $data->editor);
    }

    public function test_per_field_override_wins_over_config(): void
    {
        config()->set('kinetix.forms.rich_editor', 'basic');

        $this->assertSame('tiptap', RichEditor::make('body')->tiptap()->toData('create', null)->editor);
        $this->assertSame('markdown', RichEditor::make('body')->markdown()->toData('create', null)->editor);
        $this->assertSame('basic', RichEditor::make('body')->editor('basic')->toData('create', null)->editor);
    }

    public function test_invalid_driver_falls_back_to_config_default(): void
    {
        config()->set('kinetix.forms.rich_editor', 'markdown');

        $data = RichEditor::make('body')->editor('nope')->toData('create', null);

        $this->assertSame('markdown', $data->editor);
    }
}
