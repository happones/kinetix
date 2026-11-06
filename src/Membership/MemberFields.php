<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Forms\Components\Component;
use Illuminate\Database\Eloquent\Model;

/**
 * Extra data a host collects about a member: a flag, a few text fields, the
 * branches they work in. Point `kinetix.membership.fields` at a subclass
 * (a class-string, so it survives `config:cache`); it is resolved through the
 * container.
 *
 * The values are validated by the Kinetix forms engine, kept as JSON on the
 * pending provision, handed to {@see apply()} when the account is created, and
 * from then on live wherever `apply()` put them and are read back by
 * {@see values()}.
 */
abstract class MemberFields
{
    /**
     * The form components. Resolve option lists here, for `$context->teamId`:
     * every Select, Radio and CheckboxList only accepts its own options.
     *
     * @return array<int, Component>
     */
    abstract public function schema(MemberFieldsContext $context): array;

    /**
     * The current values of an ACTIVE member, read from wherever `apply()`
     * writes them. Keys are the schema's field names.
     *
     * @return array<string, mixed>
     */
    abstract public function values(Model $user, MemberFieldsContext $context): array;

    /**
     * Write the validated values onto the member's account.
     *
     * Runs inside the Kinetix transaction, with spatie's team pinned to the
     * provision's team, on activation, on direct provisioning and when an
     * active member is edited. `$values` is the full state, not a diff. At
     * activation the values were validated when the admin submitted them, so
     * re-scope ids to `$provision->team_id` on write; keys may be missing for
     * an invitation sent before the fields existed.
     *
     * @param array<string, mixed> $values
     */
    abstract public function apply(Model $user, MemberProvision $provision, array $values, MemberFieldsContext $context): void;
}
