<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Support\AutoLabel;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;

class AutoLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Loading the JSON catalog later would replace lines added before it.
        app('translator')->get('Name', [], 'es');
        app('translator')->addLines(['*.Name' => 'Nombre', '*.User agent' => 'Agente de usuario'], 'es');
        app()->setLocale('es');
    }

    public function test_derived_labels_go_through_the_translator(): void
    {
        $this->assertSame('Nombre', TextColumn::make('name')->toArray()['label']);
        $this->assertSame('Nombre', SelectFilter::make('name')->getLabel());
        $this->assertSame('Nombre', TextInput::make('name')->toArray()['label']);
        $this->assertSame('Nombre', TextEntry::make('name')->toArray()['label']);
    }

    public function test_a_sentence_case_key_is_found_too(): void
    {
        $this->assertSame('Agente de usuario', AutoLabel::from('user_agent'));
    }

    public function test_an_untranslated_label_keeps_its_headline(): void
    {
        $this->assertSame('Created At', AutoLabel::from('created_at'));
        $this->assertSame('User Name', AutoLabel::from('user.name'));
    }
}
