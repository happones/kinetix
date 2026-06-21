<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ExportController
{
    protected string $directory = 'kinetix-exports';

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

        // Constrain to the export directory and require the file to still exist.
        if (
            !is_string($path)
            || !str_starts_with($path, $this->directory.'/')
            || str_contains($path, '..')
            || !Storage::exists($path)
        ) {
            abort(404);
        }

        return Storage::download($path, (string) $name);
    }
}
