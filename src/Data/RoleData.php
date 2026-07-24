<?php

declare(strict_types=1);

namespace Happones\Kinetix\Data;

use Spatie\LaravelData\Data;
use Spatie\Permission\Models\Role;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RoleData extends Data
{
    /**
     * @param array<int, string> $permissions
     */
    public function __construct(
        public int|string|null $id,
        public string $name,
        public array $permissions,
        public ?int $usersCount = null,
        /** With spatie teams active: a team-NULL role visible in every team. */
        public bool $isGlobal = false,
    ) {}

    /**
     * @param Role $role
     */
    public static function fromModel($role): self
    {
        $usersCount = $role->getAttribute('users_count');

        $teamsKey = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');
        $isGlobal = (bool) config('permission.teams', false)
            && $role->getAttribute($teamsKey) === null;

        return new self(
            $role->getKey(),
            $role->name,
            $role->permissions->pluck('name')->values()->all(),
            $usersCount !== null ? (int) $usersCount : null,
            $isGlobal,
        );
    }
}
