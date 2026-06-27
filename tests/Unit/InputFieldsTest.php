<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\PinInput;
use Happones\Kinetix\Forms\Components\Rating;
use Happones\Kinetix\Forms\Components\Slider;
use Happones\Kinetix\Tests\TestCase;

class InputFieldsTest extends TestCase
{
    public function test_slider_serializes_bounds_via_number_config(): void
    {
        $data = Slider::make('volume')->min(0)->max(100)->step(5)->toData('create', null);

        $this->assertSame('slider', $data->type);
        $this->assertSame(0.0, $data->numberConfig['min']);
        $this->assertSame(100.0, $data->numberConfig['max']);
        $this->assertSame(5.0, $data->numberConfig['step']);
    }

    public function test_rating_serializes_max_and_half(): void
    {
        $data = Rating::make('score')->max(10)->allowHalf()->toData('create', null);

        $this->assertSame('rating', $data->type);
        $this->assertSame(10, $data->ratingConfig['max']);
        $this->assertTrue($data->ratingConfig['allowHalf']);
    }

    public function test_pin_input_serializes_config(): void
    {
        $data = PinInput::make('code')->length(4)->numeric()->mask()->otp()->toData('create', null);

        $this->assertSame('pin-input', $data->type);
        $this->assertSame(4, $data->pinConfig['length']);
        $this->assertTrue($data->pinConfig['mask']);
        $this->assertTrue($data->pinConfig['otp']);
        $this->assertSame('number', $data->pinConfig['type']);
    }
}
