<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\SignaturePad;
use Happones\Kinetix\Forms\Components\SlugInput;
use Happones\Kinetix\Tests\TestCase;

class SlugSignatureFieldsTest extends TestCase
{
    public function test_slug_input_serializes_source_and_separator(): void
    {
        $data = SlugInput::make('slug')->from('title')->separator('_')->toData('create', null);

        $this->assertSame('slug-input', $data->type);
        $this->assertSame('title', $data->slugConfig['from']);
        $this->assertSame('_', $data->slugConfig['separator']);
    }

    public function test_slug_separator_defaults_to_dash(): void
    {
        $data = SlugInput::make('slug')->from('name')->toData('create', null);

        $this->assertSame('-', $data->slugConfig['separator']);
    }

    public function test_signature_pad_serializes_config(): void
    {
        $data = SignaturePad::make('sign')->penColor('#1d4ed8')->backgroundColor('#fff')->height(200)->toData('create', null);

        $this->assertSame('signature-pad', $data->type);
        $this->assertSame('#1d4ed8', $data->signatureConfig['penColor']);
        $this->assertSame('#fff', $data->signatureConfig['backgroundColor']);
        $this->assertSame(200, $data->signatureConfig['height']);
    }

    public function test_signature_height_has_a_floor(): void
    {
        $data = SignaturePad::make('sign')->height(10)->toData('create', null);

        $this->assertSame(80, $data->signatureConfig['height']);
    }
}
