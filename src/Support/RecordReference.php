<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The signed record reference the comments and tags endpoints accept in
 * place of a browser-supplied type + id.
 *
 * A reference is a {@see SignedDescriptor} that names one record (its morph
 * class and key), so the endpoint never loads a record the page did not hand
 * out: it is bound to the user, the team and the audience it was minted for,
 * and it expires. The raw type + id stays available as a legacy path, which
 * `{module}.require_reference` closes under teams.
 *
 * @internal
 */
final class RecordReference
{
    /**
     * Seal a reference to the record for the given audience.
     *
     * @param array<string, mixed> $claims extra claims the endpoint reads back (e.g. `editable`)
     */
    public static function seal(Model $record, DescriptorAudience $audience, array $claims = []): string
    {
        return SignedDescriptor::seal([
            'type' => $record->getMorphClass(),
            'id'   => $record->getKey(),
        ] + $claims, $audience);
    }

    /**
     * Open a reference sent to an endpoint: aborts 400 when it is unreadable,
     * 403 when this request may not use it, 404 when its type is no longer
     * allowlisted or its record is gone.
     *
     * @param  callable(string): (class-string<Model>|null)       $allowlist resolves an allowlisted class from a type
     * @return array{record: Model, claims: array<string, mixed>}
     */
    public static function open(string $token, Request $request, DescriptorAudience $audience, callable $allowlist): array
    {
        $payload = SignedDescriptor::open($token);

        abort_if($payload === null, 400, __('kinetix.table_invalid_signature'));

        $rejection = SignedDescriptor::rejection($payload, $request, $audience);

        if ($rejection !== null) {
            abort(403, $rejection === DescriptorRejection::Expired
                ? __('kinetix.table_descriptor_expired')
                : __('kinetix.table_write_forbidden'));
        }

        $class = is_string($payload['type'] ?? null) ? $allowlist($payload['type']) : null;

        abort_if($class === null, 404);

        $id     = $payload['id'] ?? null;
        $record = is_int($id) || is_string($id) ? $class::query()->find($id) : null;

        abort_unless($record instanceof Model, 404);

        return ['record' => $record, 'claims' => $payload];
    }

    /**
     * Whether a module's endpoints insist on a reference
     * (`kinetix.{module}.require_reference`): null follows `kinetix.teams`.
     */
    public static function required(string $module): bool
    {
        $required = config("kinetix.{$module}.require_reference");

        return $required === null ? (bool) config('kinetix.teams', false) : (bool) $required;
    }

    /**
     * Authorize the ability on the record. A host policy is honoured when one
     * exists; without one the record is allowed, unless the policy is
     * mandatory (the legacy type + id path under teams), which then fails
     * closed.
     */
    public static function authorize(Model $record, string $ability, bool $policyRequired = false): void
    {
        if (Gate::getPolicyFor($record) === null) {
            abort_if($policyRequired, 403);

            return;
        }

        abort_if(Gate::denies($ability, $record), 403);
    }
}
