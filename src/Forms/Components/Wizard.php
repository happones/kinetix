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

    protected string $variant = 'default';

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
            slug: $this->slug,
        );
    }
}
