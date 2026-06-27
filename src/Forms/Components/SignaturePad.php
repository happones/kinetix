<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A signature pad. The user draws on a canvas; the value is stored as a PNG
 * data URL. Persist it to a TEXT column, or decode + store it as a file in a
 * dehydrateStateUsing() callback.
 *
 *     SignaturePad::make('signature');
 *     SignaturePad::make('signature')->penColor('#1d4ed8')->height(180);
 */
class SignaturePad extends Field
{
    protected string $penColor = '#0f172a';

    protected ?string $backgroundColor = null;

    protected int $height = 160;

    protected function getType(): string
    {
        return 'signature-pad';
    }

    public function penColor(string $color): static
    {
        $this->penColor = $color;

        return $this;
    }

    public function backgroundColor(string $color): static
    {
        $this->backgroundColor = $color;

        return $this;
    }

    public function height(int $height): static
    {
        $this->height = max(80, $height);

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->signatureConfig = [
            'penColor'        => $this->penColor,
            'backgroundColor' => $this->backgroundColor,
            'height'          => $this->height,
        ];

        return $data;
    }
}
