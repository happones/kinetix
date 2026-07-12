<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A multi-step form layout. Each child is a {@see Step} with its own schema;
 * the renderer shows one step at a time with a progress indicator and
 * Back/Next/Finish navigation. Advancing is blocked until the required fields
 * in the current step are filled.
 *
 *     Wizard::make()->variant('panels')->steps([
 *         Step::make('Account')->schema([...]),
 *         Step::make('Profile')->icon('user')->schema([...]),
 *     ]);
 */
class Wizard extends Component
{
    /**
     * @var array<int, Step>
     */
    protected array $schema = [];

    protected string $variant = 'stepper';

    protected string $orientation = 'horizontal';

    protected bool $fullWidth = true;

    protected string $stepLayout = 'inline';

    protected ?string $slug = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * @param array<int, Step> $steps
     */
    public function steps(array $steps): static
    {
        $this->schema = $steps;

        return $this;
    }

    /**
     * Step-indicator style: default | simple | vertical | panels | gradient.
     */
    public function variant(string $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    /**
     * Indicator orientation for the `stepper` / `vertical` variants:
     * horizontal | vertical.
     */
    public function orientation(string $orientation): static
    {
        $this->orientation = $orientation;

        return $this;
    }

    /**
     * Whether the (horizontal) indicator stretches to fill the container,
     * distributing steps evenly. Pass `false` for a compact, centered
     * indicator sized to its content.
     */
    public function fullWidth(bool $fullWidth = true): static
    {
        $this->fullWidth = $fullWidth;

        return $this;
    }

    /**
     * How each step's indicator + label are arranged (`stepper` variant,
     * horizontal orientation only): `inline` (default) side by side,
     * `stacked` indicator on top, or `tooltip` indicator only + label on
     * hover/focus — the most compact option for many steps.
     */
    public function stepLayout(string $stepLayout): static
    {
        $this->stepLayout = $stepLayout;

        return $this;
    }

    /**
     * Tie this wizard to a gating slug so it can mark completion via the
     * `kinetix.wizard:<slug>` middleware on finish.
     */
    public function slug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    protected function getType(): string
    {
        return 'wizard';
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $stepData = [];
        foreach ($this->schema as $step) {
            $data = $step->toData($operation, $record);
            if ($data !== null) {
                $stepData[] = $data;
            }
        }

        if ($stepData === []) {
            return null;
        }

        return new FormFieldData(
            type: $this->getType(),
            columnSpan: $this->columnSpan,
            schema: $stepData,
            variant: $this->variant,
            orientation: $this->orientation,
            fullWidth: $this->fullWidth,
            stepLayout: $this->stepLayout,
            slug: $this->slug,
        );
    }
}
