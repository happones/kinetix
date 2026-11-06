<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership\Events;

use Happones\Kinetix\Membership\MemberFieldsForm;
use Happones\Kinetix\Membership\MemberProvision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An admin changed a member: their role, their custom fields, or both. Fires
 * only when something really changed.
 *
 * Dispatched after commit.
 */
class MemberUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed>      $previousFields the custom fields before the change
     * @param array<string, mixed>|null $fields         the custom fields after it; null when the update did not touch them
     */
    public function __construct(
        public readonly MemberProvision $provision,
        public readonly ?Authenticatable $actor,
        public readonly string $previousRole,
        public readonly string $role,
        public readonly array $previousFields = [],
        public readonly ?array $fields = null,
    ) {}

    public function roleChanged(): bool
    {
        return $this->previousRole !== $this->role;
    }

    public function fieldsChanged(): bool
    {
        return $this->fields                              !== null
            && MemberFieldsForm::normalize($this->fields) !== MemberFieldsForm::normalize($this->previousFields);
    }
}
