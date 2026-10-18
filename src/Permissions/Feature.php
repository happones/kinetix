<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Support\KinetixTrans;

/**
 * A permission "feature" (a resource or module) and the abilities it exposes.
 * Permission keys are namespaced as `{feature}.{ability}` (e.g. `posts.update`),
 * which is what flows through Laravel's Gate and the frontend `can()` map.
 */
class Feature
{
    protected ?string $label = null;

    protected ?string $group = null;

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
     * Group related features into a titled section in the role-management UIs
     * (e.g. 'HR', 'Sales'). Optional — ungrouped features list last.
     */
    public function group(string $group): static
    {
        $this->group = $group;

        return $this;
    }

    /**
     * Apply the standard CRUD ability preset.
     */
    public function crud(): static
    {
        return $this->abilities([
            'viewAny' => 'kinetix.permission_ability_view_any',
            'view'    => 'kinetix.permission_ability_view',
            'create'  => 'kinetix.permission_ability_create',
            'update'  => 'kinetix.permission_ability_update',
            'delete'  => 'kinetix.permission_ability_delete',
        ]);
    }

    /**
     * Access-only preset for modules with no per-record CRUD (dashboards,
     * report sections, …): a single `{feature}.access` ability — "can enter
     * the module or not". Renders as the matrix's first canonical column.
     */
    public function access(): static
    {
        return $this->ability('access', 'kinetix.permission_ability_access');
    }

    /**
     * Add soft-delete abilities (restore / force delete) on top of CRUD.
     */
    public function softDeletes(): static
    {
        return $this->abilities([
            'restore'     => 'kinetix.permission_ability_restore',
            'forceDelete' => 'kinetix.permission_ability_force_delete',
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

    /**
     * The label in the reader's language. Read here, not where the feature
     * is declared: features are declared while the app boots, before the
     * request's locale is known. A translation key (`kinetix.…`, or a host's
     * own) or a plain string a JSON lang file translates both work; one with
     * no translation reads as written.
     */
    public function getLabel(): string
    {
        return self::translated($this->label ?? (string) str($this->name)->headline());
    }

    public function getGroup(): ?string
    {
        return $this->group === null ? null : self::translated($this->group);
    }

    /**
     * @return array<string, string>
     */
    public function getAbilities(): array
    {
        return array_map(self::translated(...), $this->abilities);
    }

    private static function translated(string $label): string
    {
        return KinetixTrans::line($label);
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
