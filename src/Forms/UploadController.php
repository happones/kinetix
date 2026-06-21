<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            return response()->json(['message' => 'Invalid upload field.'], 422);
        }

        $validator = Validator::make(
            ['file' => $request->file('file')],
            ['file' => $this->buildRules($config)]
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file')], 422);
        }

        $disk = (string) ($config['disk'] ?? 'public');
        $directory = (string) ($config['directory'] ?? 'uploads');

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
            return response()->json(['message' => 'Invalid upload field.'], 422);
        }

        $disk = (string) ($config['disk'] ?? 'public');
        $directory = trim((string) ($config['directory'] ?? 'uploads'), '/');
        $path = $request->string('path')->toString();

        if (!str_starts_with($path, $directory.'/') || str_contains($path, '..')) {
            return response()->json(['message' => 'Invalid file path.'], 403);
        }

        Storage::disk($disk)->delete($path);

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Build Laravel validation rules for the uploaded file from the field config.
     *
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    protected function buildRules(array $config): array
    {
        $rules = ['required', 'file'];

        if (!empty($config['image'])) {
            $rules[] = 'image';
        }

        if (!empty($config['maxSize'])) {
            $rules[] = 'max:'.(int) $config['maxSize'];
        }

        $accept = $config['accept'] ?? [];

        if (is_array($accept) && $accept !== []) {
            $mimeTypes = array_filter($accept, fn ($type) => str_contains((string) $type, '/'));
            $extensions = array_filter($accept, fn ($type) => !str_contains((string) $type, '/'));

            if ($mimeTypes !== []) {
                $rules[] = 'mimetypes:'.implode(',', $mimeTypes);
            }

            if ($extensions !== []) {
                $rules[] = 'mimes:'.implode(',', $extensions);
            }
        }

        return $rules;
    }
}
