<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Data\ImportPreviewData;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ImportController
{
    /**
     * Number of sample rows returned for the preview table.
     */
    protected int $previewRows = 10;

    protected string $storageDirectory = 'kinetix-imports';

    /**
     * Store an uploaded file and return the parsed preview + automatic mapping.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'     => ['required', 'file', 'mimes:csv,txt,tsv,xls,xlsx'],
            'importer' => ['required', 'string'],
        ]);

        try {
            $importer = Importer::fromToken($request->string('importer')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Invalid importer.'], 422);
        }

        $path = $request->file('file')->store($this->storageDirectory);

        return response()->json(
            $this->buildPreview($importer, $path, $this->optionsFromRequest($request))->toArray()
        );
    }

    /**
     * Re-parse an already-uploaded file with new CSV options.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'importer'  => ['required', 'string'],
            'fileToken' => ['required', 'string'],
        ]);

        try {
            $importer = Importer::fromToken($request->string('importer')->toString());
            $path = $this->resolvePath($request->string('fileToken')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Invalid import session.'], 422);
        }

        return response()->json(
            $this->buildPreview($importer, $path, $this->optionsFromRequest($request))->toArray()
        );
    }

    /**
     * Validate the chosen mapping and dispatch the queued import job.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'importer'  => ['required', 'string'],
            'fileToken' => ['required', 'string'],
            'mapping'   => ['required', 'array'],
        ]);

        try {
            $importer = Importer::fromToken($request->string('importer')->toString());
            $path = $this->resolvePath($request->string('fileToken')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Invalid import session.'], 422);
        }

        /** @var array<string, int|null> $mapping */
        $mapping = $request->array('mapping');

        // isset() is false for both absent keys and null values, covering "not mapped".
        $missing = array_filter(
            $importer::getRequiredColumns(),
            fn (string $column) => !isset($mapping[$column])
        );

        if ($missing !== []) {
            return response()->json([
                'message' => 'Required columns are not mapped.',
                'missing' => array_values($missing),
            ], 422);
        }

        $user = $request->user();

        ImportProcessor::dispatch(
            $importer::class,
            $path,
            $this->optionsFromRequest($request)->toArray(),
            $mapping,
            $user !== null ? $user::class : null,
            $user?->getKey(),
        )->onQueue($importer->queue() ?? config('queue.default'));

        return response()->json(['status' => 'queued']);
    }

    /**
     * Build the preview payload for a stored file.
     */
    protected function buildPreview(Importer $importer, string $path, ImportOptionsData $options): ImportPreviewData
    {
        $absolutePath = Storage::path($path);

        $parsed = FileReader::read($absolutePath, $options, $this->previewRows);
        $total = FileReader::countRows($absolutePath, $options);

        return new ImportPreviewData(
            headers: $parsed['headers'],
            rows: $parsed['rows'],
            columns: $importer::getColumnsData(),
            options: $options,
            autoMapping: $importer::guessMapping($parsed['headers']),
            fileToken: Crypt::encryptString($path),
            totalRows: $total,
        );
    }

    /**
     * Decrypt and sanitize the stored file path from the token.
     */
    protected function resolvePath(string $token): string
    {
        $path = Crypt::decryptString($token);

        // Constrain access to the import storage directory to prevent traversal.
        if (!str_starts_with($path, $this->storageDirectory.'/') || str_contains($path, '..')) {
            throw new \RuntimeException('Invalid file token.');
        }

        return $path;
    }

    protected function optionsFromRequest(Request $request): ImportOptionsData
    {
        return new ImportOptionsData(
            delimiter: $request->input('delimiter', ','),
            enclosure: $request->input('enclosure', '"'),
            skipLines: (int) $request->input('skipLines', 0),
            hasHeader: $request->boolean('hasHeader', true),
        );
    }
}
