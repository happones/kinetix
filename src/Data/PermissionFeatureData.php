<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Happones\Kinetix\Permissions\Feature;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PermissionFeatureData extends Data
{
    /**
     * @param array<int, array{key: string, label: string, permission: string}> $abilities
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $abilities,
        /** Optional section title for the role-management UIs. */
        public ?string $group = null,
    ) {}

    public static function fromFeature(Feature $feature): self
    {
        $abilities = [];

        foreach ($feature->getAbilities() as $key => $label) {
            $abilities[] = [
                'key'        => $key,
                'label'      => $label,
                'permission' => "{$feature->name}.{$key}",
            ];
        }

        return new self($feature->name, $feature->getLabel(), $abilities, $feature->getGroup());
    }
}
