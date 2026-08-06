<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExportController
{
    protected string $directory = 'kinetix-exports';

    /**
     * Dispatch a queued export from an `ExportAction` (the exporter travels as a
     * signed token; optional `ids` scope a bulk export). The user is notified
     * with a download link when the export finishes.
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $exporter = Exporter::fromToken(
                (string) $request->input('exporter', ''),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.export_invalid')], 422);
        }

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
     * related model must be exactly what the exporter exports.
     *
     * @return array{parent: class-string<Model>, key: mixed, name: string}|JsonResponse
     */
    protected function relationScope(Request $request, Exporter $exporter): array|JsonResponse
    {
        $invalid = fn (): JsonResponse => response()->json(['message' => __('kinetix.export_invalid')], 422);

        try {
            $payload = Crypt::decrypt((string) $request->input('relation'));
        } catch (Throwable) {
            return $invalid();
        }

        $parentClass  = is_array($payload) ? ($payload['parent'] ?? null) : null;
        $relationName = is_array($payload) ? ($payload['relation'] ?? null) : null;

        if (
            ! is_string($parentClass) || ! class_exists($parentClass)
                                      || ! is_subclass_of($parentClass, Model::class)
                                      || ! is_string($relationName) || $relationName === ''
                                      || ! method_exists($parentClass, $relationName)
        ) {
            return $invalid();
        }

        // Bound to the user it was minted for, and expiring.
        $mintedFor = $payload['user'] ?? null;

        if ($mintedFor !== null && (string) $mintedFor !== (string) $request->user()?->getAuthIdentifier()) {
            return response()->json(['message' => __('kinetix.export_forbidden')], 403);
        }

        $expiresAt = $payload['expires'] ?? null;

        if (is_int($expiresAt) && $expiresAt < now()->getTimestamp()) {
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

        $relation = $parent->{$relationName}();

        if (! $relation instanceof Relation || $relation->getRelated()::class !== $exporter::getModel()) {
            return $invalid();
        }

        return [
            'parent' => $parentClass,
            'key'    => $parent->getKey(),
            'name'   => $relationName,
        ];
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
