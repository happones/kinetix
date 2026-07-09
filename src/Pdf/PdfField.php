<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

/**
 * One configurable knob of a PDF template — declared in
 * {@see PdfTemplate::fields()} and rendered as a control by
 * `<KinetixPdfTemplate>` (color swatches, text input, select, toggle, number).
 *
 *     PdfField::color('accent')->label('Accent color')->default('#6366f1'),
 *     PdfField::toggle('striped')->label('Striped rows')->default(true),
 *     PdfField::select('font', ['sans' => 'Sans', 'serif' => 'Serif']),
 */
class PdfField
{
    protected ?string $label = null;

    protected mixed $default = null;

    protected ?string $help = null;

    /**
     * @var array<int, string>
     */
    protected array $palette = [];

    /**
     * @var array<string, string>
     */
    protected array $options = [];

    protected ?int $maxLength = null;

    final protected function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {}

    public static function color(string $name): static
    {
        return new static($name, 'color');
    }

    public static function text(string $name): static
    {
        return new static($name, 'text');
    }

    public static function toggle(string $name): static
    {
        return (new static($name, 'toggle'))->default(false);
    }

    public static function number(string $name): static
    {
        return new static($name, 'number');
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, array $options): static
    {
        $field          = new static($name, 'select');
        $field->options = $options;

        return $field;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function default(mixed $default): static
    {
        $this->default = $default;

        return $this;
    }

    public function help(string $help): static
    {
        $this->help = $help;

        return $this;
    }

    /**
     * Preset swatches shown for a color field (a hex input is always offered).
     *
     * @param array<int, string> $colors
     */
    public function palette(array $colors): static
    {
        $this->palette = $colors;

        return $this;
    }

    public function maxLength(int $maxLength): static
    {
        $this->maxLength = $maxLength;

        return $this;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * Cast a raw (query/request) value into this field's native type.
     */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : $this->default,
            default  => $value === null ? null : (string) $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'type'      => $this->type,
            'label'     => $this->label ?? (string) str($this->name)->headline(),
            'default'   => $this->default,
            'help'      => $this->help,
            'palette'   => $this->palette,
            'options'   => $this->options,
            'maxLength' => $this->maxLength,
        ];
    }
}
