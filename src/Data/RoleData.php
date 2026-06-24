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
    ) {}

    /**
     * @param Role $role
     */
    public static function fromModel($role): self
    {
        return new self(
            $role->getKey(),
            $role->name,
            $role->permissions->pluck('name')->values()->all(),
        );
    }
}
