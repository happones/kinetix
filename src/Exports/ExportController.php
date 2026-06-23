<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
            return response()->json(['message' => 'Invalid exporter.'], 422);
        }

        /** @var array<int, mixed> $ids */
        $ids = (array) $request->input('ids', []);

        $exporter->export($request->user(), $ids !== [] ? ['ids' => $ids] : []);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Stream a generated export file referenced by a signed token.
     */
    public function download(Request $request): StreamedResponse
    {
        $token = (string) $request->input('token', '');

        try {
            $payload = Crypt::decrypt($token);
        } catch (Throwable $e) {
            abort(403);
        }

        $path = is_array($payload) ? ($payload['path'] ?? '') : '';
        $name = is_array($payload) ? ($payload['name'] ?? 'export') : 'export';
        $disk = is_array($payload) && is_string($payload['disk'] ?? null)
            ? $payload['disk']
            : (string) config('kinetix.filesystem.disk', 'public');

        // Constrain to the export directory and require the file to still exist.
        if (
            ! is_string($path)
            || ! str_starts_with($path, $this->directory.'/')
            || str_contains($path, '..')
            || ! Storage::disk($disk)->exists($path)
        ) {
            abort(404);
        }

        return Storage::disk($disk)->download($path, (string) $name);
    }
}
