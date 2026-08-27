<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pending/active provisioning record — Kinetix's own directory of who an admin
 * added to a team and with which role.
 *
 * In the default `activation` mode the host app's `User` is only created when
 * the person activates, so no password-less accounts pile up. In `direct` mode
 * it is created immediately with a temporary credential — the trade for working
 * with no delivery channel at all.
 *
 * @property int|string            $id
 * @property int|string|null       $team_id
 * @property string|null           $email
 * @property string|null           $username
 * @property string|null           $phone
 * @property string|null           $name
 * @property string                $role
 * @property int|string|null       $invited_by
 * @property int|string|null       $user_id
 * @property MemberProvisionStatus $status
 * @property Carbon|null           $expires_at
 * @property Carbon|null           $activated_at
 */
class MemberProvision extends Model
{
    protected $table = 'kinetix_member_provisions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'       => MemberProvisionStatus::class,
            'expires_at'   => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === MemberProvisionStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The identifier this member was provisioned with, whichever field carries
     * it, or null for a record that predates the other identifiers.
     */
    public function identifier(): ?string
    {
        foreach (['email', 'username', 'phone'] as $field) {
            $value = $this->getAttribute($field);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
