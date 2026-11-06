<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\EloquentRelation;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExportController
{
    protected string $directory = 'kinetix-exports';

    /**
     * Dispatch a queued export from an `ExportAction` (the exporter travels as a
     * signed token; optional `ids` scope a bulk export). The user is notified
     * with a download link when the export finishes.
     *
     * The route is not team-prefixed, so the team comes from the token: the
     * request is bound to the team the export button was rendered in (its
     * `team` claim), the user's membership in it is checked, and the
     * authorization, the relation scope and the captured queue context all
     * run in that team, never in the user's stored `currentTeam`.
     */
    public function start(Request $request): JsonResponse
    {
        $token = (string) $request->input('exporter', '');

        $this->bindTokenTeam($request, SignedDescriptor::open($token));

        try {
            $team = config('kinetix.teams', false) ? KinetixTeams::currentTeamKey($request) : null;
        } catch (HttpExceptionInterface) {
            return response()->json(['message' => __('kinetix.export_forbidden')], 403);
        }

        try {
            $exporter = Exporter::fromToken($token);
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.export_invalid')], 422);
        }

        // No team resolved (teams off, or no team context): leave spatie's
        // team id as the host's own middleware set it.
        if ($team === null) {
            return $this->dispatchExport($request, $exporter);
        }

        $user = $request->user();

        return PermissionTeam::run(
            $team,
            fn (): JsonResponse => $this->dispatchExport($request, $exporter),
            $user instanceof Model ? $user : null,
        );
    }

    /**
     * Bind the request to the team claim of the exporter token, so every
     * team resolution below ({@see KinetixTeams::currentTeam()}, the
     * descriptor team checks, `keyFor()`) reads the team the token was
     * minted in. A token minted with no team context leaves the request as
     * it is (the stored team applies, as before).
     *
     * @param array<string, mixed>|null $payload
     */
    protected function bindTokenTeam(Request $request, ?array $payload): void
    {
        if (! config('kinetix.teams', false) || $payload === null) {
            return;
        }

        $claim = $payload['team'] ?? null;

        if (! (is_string($claim) && $claim !== '') && ! is_int($claim)) {
            return;
        }

        $route = $request->route();

        if (! $route instanceof Route || ($route->parameter('current_team') ?? $route->parameter('team')) !== null) {
            return;
        }

        $route->setParameter('current_team', (string) $claim);
    }

    /**
     * Authorize and queue the export, inside the request's team.
     */
    protected function dispatchExport(Request $request, Exporter $exporter): JsonResponse
    {
        if (! $exporter->authorize($request->user())) {
            return response()->json(['message' => __('kinetix.export_forbidden')], 403);
        }

        // Only scalar ids: a nested array would widen `whereKey()` unpredictably.
        // They are applied on top of the exporter's own query(), so they can only
        // ever narrow what that query already allows.
        $ids = array_values(array_filter(
            (array) $request->input('ids', []),
            static fn (mixed $id): bool => is_scalar($id),
        ));

        $parameters = $ids !== [] ? ['ids' => $ids] : [];

        // A relation manager's export carries its signed descriptor: the export
        // is then narrowed to the parent's related records (on top of the
        // exporter's own query, so it can only ever narrow).
        if ($request->filled('relation')) {
            $scope = $this->relationScope($request, $exporter);

            if ($scope instanceof JsonResponse) {
                return $scope;
            }

            $parameters['relation'] = $scope;
        }

        $exporter->export($request->user(), $parameters);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Validate a relation manager's signed descriptor into the relation scope
     * the queued exporter narrows by. The descriptor is user-bound and expiring
     * (same contract as every relation endpoint); the parent's `view` policy
     * rules (exporting children is reading the parent), and the relation's
     * related model must be exactly what the exporter exports. The scope is
     * handed to the job as a {@see RelationScope} seal, the only form the
     * exporter honours.
     */
    protected function relationScope(Request $request, Exporter $exporter): string|JsonResponse
    {
        $invalid = fn (): JsonResponse => response()->json(['message' => __('kinetix.export_invalid')], 422);

        try {
            $payload = Crypt::decrypt((string) $request->input('relation'));
        } catch (Throwable) {
            return $invalid();
        }

        $parentClass  = is_array($payload) ? ($payload['parent'] ?? null) : null;
        $relationName = is_array($payload) ? ($payload['relation'] ?? null) : null;

        if (! is_string($parentClass) || ! is_subclass_of($parentClass, Model::class)) {
            return $invalid();
        }

        if (! is_string($relationName) || $relationName === '') {
            return $invalid();
        }

        // Bound to the user it was minted for, and expiring; and to the team
        // the exporter token was minted in, which start() bound the request to.
        if (SignedDescriptor::rejection($payload, $request, DescriptorAudience::RelationManager) !== null) {
            return response()->json(['message' => __('kinetix.export_forbidden')], 403);
        }

        $parent = $parentClass::query()->whereKey($payload['key'] ?? null)->first();

        if ($parent === null) {
            return $invalid();
        }

        // Exporting children is READING the parent — its `view` policy rules.
        if (Gate::getPolicyFor($parentClass) !== null && ! Gate::forUser($request->user())->allows('view', $parent)) {
            return response()->json(['message' => __('kinetix.export_forbidden')], 403);
        }

        $relation = EloquentRelation::resolve($parent, $relationName);

        if ($relation === null || $relation->getRelated()::class !== $exporter::getModel()) {
            return $invalid();
        }

        return RelationScope::seal($parent, $relationName, $exporter::class);
    }

    /**
     * Stream a generated export file referenced by a signed token.
     */
    public function download(Request $request): StreamedResponse
    {
        // The token is bound to the user it was minted for and expires, so a link
        // that leaks out of a mailbox or a proxy log isn't a standing grant.
        $payload = DownloadToken::open((string) $request->input('token', ''), $request->user());

        if ($payload === null) {
            abort(403);
        }

        ['disk' => $disk, 'path' => $path, 'name' => $name] = $payload;

        // Constrain to the export directory and require the file to still exist.
        if (
            ! str_starts_with($path, $this->directory.'/')
            || str_contains($path, '..')
            || ! Storage::disk($disk)->exists($path)
        ) {
            abort(404);
        }

        return Storage::disk($disk)->download($path, $name);
    }
}
