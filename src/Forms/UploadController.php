<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UploadController
{
    /**
     * Validate and store an uploaded file for a FileUpload field.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'  => ['required', 'file'],
            'token' => ['required', 'string'],
        ]);

        try {
            /** @var array<string, mixed> $config */
            $config = Crypt::decrypt($request->string('token')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.upload_invalid_field')], 422);
        }

        $validator = Validator::make(
            ['file' => $request->file('file')],
            ['file' => $this->buildRules($config)]
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file')], 422);
        }

        $disk      = (string) ($config['disk'] ?? config('kinetix.filesystem.disk', 'public'));
        $directory = $this->directoryFor($config, $request);

        $path = $request->file('file')->store($directory, $disk);

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk($disk)->url($path),
            'name' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    /**
     * Delete a previously uploaded file, constrained to the field's directory.
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'path'  => ['required', 'string'],
            'token' => ['required', 'string'],
        ]);

        try {
            /** @var array<string, mixed> $config */
            $config = Crypt::decrypt($request->string('token')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.upload_invalid_field')], 422);
        }

        $disk = (string) ($config['disk'] ?? config('kinetix.filesystem.disk', 'public'));

        // The directory is per-user when upload scoping is on, so this prefix
        // check is also the ownership check: one user cannot name another user's
        // path. Without scoping, every upload shares one flat directory and any
        // authenticated user could delete any file in it.
        $directory = $this->directoryFor($config, $request);
        $path      = $request->string('path')->toString();

        if (! str_starts_with($path, $directory.'/') || str_contains($path, '..')) {
            return response()->json(['message' => __('kinetix.upload_invalid_path')], 403);
        }

        Storage::disk($disk)->delete($path);

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Build Laravel validation rules for the uploaded file from the field config.
     *
     * @param  array<string, mixed>       $config
     * @return array<int, string|Closure>
     */
    protected function buildRules(array $config): array
    {
        $rules = ['required', 'file'];

        if (! empty($config['image'])) {
            $rules[] = 'image';
        }

        // A field without maxSize() falls back to the configured ceiling rather
        // than accepting an unbounded upload (a disk-fill DoS).
        $maxSize = $config['maxSize'] ?? config('kinetix.filesystem.upload_max_size', 12288);

        if (is_numeric($maxSize) && (int) $maxSize > 0) {
            $rules[] = 'max:'.(int) $maxSize;
        }

        $accept = $config['accept'] ?? [];

        if (is_array($accept) && $accept !== []) {
            $mimeTypes  = array_filter($accept, fn ($type) => str_contains((string) $type, '/'));
            $extensions = array_filter($accept, fn ($type) => ! str_contains((string) $type, '/'));

            if ($mimeTypes !== []) {
                $rules[] = 'mimetypes:'.implode(',', $mimeTypes);
            }

            if ($extensions !== []) {
                $rules[] = 'mimes:'.implode(',', $extensions);
            }

            // The field declared what it accepts, so that allowlist governs.
            return $rules;
        }

        // A field with no accept() would otherwise take anything. On a public
        // disk that means an uploaded .html or .svg is served from the app's own
        // origin — stored XSS. Reject the file types that execute in a browser
        // unless a field explicitly opts into them.
        $blocked = config('kinetix.filesystem.upload_blocked_extensions', []);

        if (is_array($blocked) && $blocked !== []) {
            $rules[] = $this->blockedExtensionRule(array_map(strval(...), $blocked));
        }

        return $rules;
    }

    /**
     * Reject files whose extension is browser-executable.
     *
     * Both the claimed extension and the one guessed from the file's contents
     * are checked, so renaming `evil.html` to `evil.png` doesn't get through.
     *
     * @param array<int, string> $blocked
     */
    protected function blockedExtensionRule(array $blocked): Closure
    {
        $blocked = array_map(strtolower(...), $blocked);

        return function (string $attribute, mixed $value, Closure $fail) use ($blocked): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $candidates = [strtolower($value->getClientOriginalExtension())];

            try {
                $candidates[] = strtolower((string) $value->extension());
            } catch (Throwable) {
                // Undetectable content type — the claimed extension still applies.
            }

            foreach (array_filter($candidates) as $extension) {
                if (in_array($extension, $blocked, true)) {
                    $fail(__('kinetix.upload_blocked_type'));

                    return;
                }
            }
        };
    }

    /**
     * The directory an upload is stored in — namespaced per user when
     * `kinetix.filesystem.scope_uploads_by_user` is on, which is what makes one
     * user unable to address (and therefore delete) another's files.
     *
     * @param array<string, mixed> $config
     */
    protected function directoryFor(array $config, Request $request): string
    {
        $directory = trim((string) ($config['directory'] ?? 'uploads'), '/');

        if (! config('kinetix.filesystem.scope_uploads_by_user', true)) {
            return $directory;
        }

        $userKey = $request->user()?->getAuthIdentifier();

        return $userKey === null
            ? $directory
            : $directory.'/'.md5((string) $userKey);
    }
}
