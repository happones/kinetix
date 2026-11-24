<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Closure;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\KinetixDisk;
use Happones\Kinetix\Support\SignedDescriptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

        $config = $this->config($request);

        if ($config instanceof JsonResponse) {
            return $config;
        }

        $validator = Validator::make(
            ['file' => $request->file('file')],
            ['file' => $this->buildRules($config)]
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file')], 422);
        }

        if (UploadDirectory::teamMissing($request)) {
            return response()->json(['message' => __('kinetix.upload_invalid_field')], 403);
        }

        $disk       = (string) ($config['disk'] ?? config('kinetix.filesystem.disk', 'public'));
        $visibility = is_string($config['visibility'] ?? null) ? $config['visibility'] : null;
        $directory  = $this->directoryFor($config, $request);
        $options    = ['disk' => $disk];

        if ($visibility !== null) {
            $options['visibility'] = $visibility;
        }

        $path = $request->file('file')->store($directory, $options);

        if ($path === false) {
            return response()->json(['message' => __('kinetix.upload_failed')], 500);
        }

        return response()->json([
            'path' => $path,
            'url'  => KinetixDisk::url($disk, $path, KinetixDisk::isPrivate($visibility)),
            'name' => $request->file('file')->getClientOriginalName(),
            // Only the uploader's own session can remove this file again: the
            // receipt names this exact path, bound to the user, team and expiry.
            'receipt' => SignedDescriptor::seal(['disk' => $disk, 'path' => $path], DescriptorAudience::UploadReceipt),
        ]);
    }

    /**
     * Delete a file this user just uploaded, named by the receipt the upload
     * returned. A file without a receipt — a record's stored file, someone
     * else's upload — is never deleted here: removing it from a field only
     * changes the field's value, and the record decides on save.
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'receipt' => ['required', 'string'],
        ]);

        $receipt = SignedDescriptor::open($request->string('receipt')->toString());

        if ($receipt === null) {
            return response()->json(['message' => __('kinetix.upload_invalid_path')], 403);
        }

        $rejection = SignedDescriptor::rejection($receipt, $request, DescriptorAudience::UploadReceipt);

        if ($rejection !== null) {
            return response()->json([
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.form_session_expired')
                    : __('kinetix.upload_invalid_path'),
            ], 403);
        }

        $disk = $receipt['disk'] ?? null;
        $path = $receipt['path'] ?? null;

        if (! is_string($disk) || ! is_string($path) || $path === '' || str_contains($path, '..')) {
            return response()->json(['message' => __('kinetix.upload_invalid_path')], 403);
        }

        Storage::disk($disk)->delete($path);

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Decode the field's signed storage config — bound to the user and team it
     * was minted for, and expiring ({@see SignedDescriptor}) — or the error
     * response to send back.
     *
     * @return array<string, mixed>|JsonResponse
     */
    protected function config(Request $request): array|JsonResponse
    {
        $config = SignedDescriptor::open($request->string('token')->toString());

        if ($config === null) {
            return response()->json(['message' => __('kinetix.upload_invalid_field')], 422);
        }

        $rejection = SignedDescriptor::rejection($config, $request, DescriptorAudience::Upload);

        if ($rejection !== null) {
            return response()->json([
                'message' => $rejection === DescriptorRejection::Expired
                    ? __('kinetix.form_session_expired')
                    : __('kinetix.upload_invalid_field'),
            ], 403);
        }

        return $config;
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
     * The directory an upload is stored in — namespaced per team when uploads
     * are team-scoped and per user when `kinetix.filesystem.scope_uploads_by_user`
     * is on ({@see UploadDirectory}).
     *
     * @param array<string, mixed> $config
     */
    protected function directoryFor(array $config, Request $request): string
    {
        $directory = $config['directory'] ?? null;

        return UploadDirectory::current(is_string($directory) ? $directory : null, $request);
    }
}
