<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A segmented PIN / OTP input (Reka UI PinInput). Stores the concatenated value
 * as a string.
 *
 *     PinInput::make('code')->length(6)->otp();
 *     PinInput::make('pin')->length(4)->numeric()->mask();
 */
class PinInput extends Field
{
    protected int $length = 6;

    protected bool $mask = false;

    protected bool $otp = false;

    protected string $inputType = 'text';

    protected function getType(): string
    {
        return 'pin-input';
    }

    public function length(int $length): static
    {
        $this->length = max(1, $length);

        return $this;
    }

    public function mask(bool $mask = true): static
    {
        $this->mask = $mask;

        return $this;
    }

    /**
     * Enable one-time-code autofill (SMS / clipboard on mobile).
     */
    public function otp(bool $otp = true): static
    {
        $this->otp = $otp;

        return $this;
    }

    public function numeric(): static
    {
        $this->inputType = 'number';

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->pinConfig = [
            'length' => $this->length,
            'mask'   => $this->mask,
            'otp'    => $this->otp,
            'type'   => $this->inputType,
        ];

        return $data;
    }
}
