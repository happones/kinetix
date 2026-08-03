<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $exporter->export($request->user(), $ids !== [] ? ['ids' => $ids] : []);

        return response()->json(['status' => 'queued']);
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
