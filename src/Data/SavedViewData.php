<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\SavedViews\SavedView;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SavedViewData extends Data
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        public int|string|null $id,
        public string $name,
        public array $state,
        public bool $isDefault,
    ) {}

    public static function fromModel(SavedView $view): self
    {
        return new self(
            id: $view->getKey(),
            name: $view->name,
            state: $view->state ?? [],
            isDefault: $view->is_default,
        );
    }
}
