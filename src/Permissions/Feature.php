<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

/**
 * A permission "feature" (a resource or module) and the abilities it exposes.
 * Permission keys are namespaced as `{feature}.{ability}` (e.g. `posts.update`),
 * which is what flows through Laravel's Gate and the frontend `can()` map.
 */
class Feature
{
    protected ?string $label = null;

    /**
     * Ability key => human label.
     *
     * @var array<string, string>
     */
    protected array $abilities = [];

    public function __construct(public readonly string $name) {}

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Apply the standard CRUD ability preset.
     */
    public function crud(): static
    {
        return $this->abilities([
            'viewAny' => 'View list',
            'view'    => 'View',
            'create'  => 'Create',
            'update'  => 'Update',
            'delete'  => 'Delete',
        ]);
    }

    /**
     * Add soft-delete abilities (restore / force delete) on top of CRUD.
     */
    public function softDeletes(): static
    {
        return $this->abilities([
            'restore'     => 'Restore',
            'forceDelete' => 'Force delete',
        ]);
    }

    public function ability(string $key, ?string $label = null): static
    {
        $this->abilities[$key] = $label ?? (string) str($key)->headline();

        return $this;
    }

    /**
     * @param array<int|string, string> $abilities list of keys, or key => label map
     */
    public function abilities(array $abilities): static
    {
        foreach ($abilities as $key => $label) {
            if (is_int($key)) {
                $this->ability($label);

                continue;
            }

            $this->ability($key, $label);
        }

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? (string) str($this->name)->headline();
    }

    /**
     * @return array<string, string>
     */
    public function getAbilities(): array
    {
        return $this->abilities;
    }

    /**
     * The fully-qualified permission keys for this feature.
     *
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        return array_map(
            fn (string $ability): string => "{$this->name}.{$ability}",
            array_keys($this->abilities),
        );
    }
}
