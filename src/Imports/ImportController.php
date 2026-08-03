<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Data\ImportOptionsData;
use Happones\Kinetix\Data\ImportPreviewData;
use Happones\Kinetix\Imports\Jobs\ImportProcessor;
use Happones\Kinetix\Support\KinetixDisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class ImportController
{
    /**
     * Number of sample rows returned for the preview table.
     */
    protected int $previewRows = 10;

    protected string $storageDirectory = 'kinetix-imports';

    /**
     * Download a CSV template for the importer: a header row of the column
     * labels (they auto-map on upload). 404 unless the importer enables it.
     */
    public function template(Request $request): mixed
    {
        try {
            $importer = Importer::fromToken($request->string('importer')->toString());
        } catch (Throwable $e) {
            abort(404);
        }

        abort_unless($importer->hasDownloadableTemplate(), 404);

        return response()->streamDownload(function () use ($importer): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $importer->getTemplateHeaders());
            fclose($handle);
        }, $importer->getTemplateFileName(), ['Content-Type' => 'text/csv']);
    }

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
            return response()->json(['message' => __('kinetix.import_invalid')], 422);
        }

        if (! $importer->authorize($request->user())) {
            return response()->json(['message' => __('kinetix.import_forbidden')], 403);
        }

        $path = $request->file('file')->store($this->storageDirectory, KinetixDisk::privateName());

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

        // Resolve and authorize the importer BEFORE touching the file token, so an
        // unauthorized caller gets a flat 403 instead of a 422 that reveals
        // whether the session they guessed at exists.
        try {
            $importer = Importer::fromToken($request->string('importer')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.import_invalid')], 422);
        }

        if (! $importer->authorize($request->user())) {
            return response()->json(['message' => __('kinetix.import_forbidden')], 403);
        }

        try {
            $path = $this->resolvePath($request->string('fileToken')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.import_invalid_session')], 422);
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
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.import_invalid')], 422);
        }

        if (! $importer->authorize($request->user())) {
            return response()->json(['message' => __('kinetix.import_forbidden')], 403);
        }

        try {
            $path = $this->resolvePath($request->string('fileToken')->toString());
        } catch (Throwable $e) {
            return response()->json(['message' => __('kinetix.import_invalid_session')], 422);
        }

        /** @var array<string, int|null> $mapping */
        $mapping = $request->array('mapping');

        // isset() is false for both absent keys and null values, covering "not mapped".
        $missing = array_filter(
            $importer::getRequiredColumns(),
            fn (string $column) => ! isset($mapping[$column])
        );

        if ($missing !== []) {
            return response()->json([
                'message' => __('kinetix.import_required_columns_missing'),
                'missing' => array_values($missing),
            ], 422);
        }

        $user = $request->user();

        $pending = ImportProcessor::dispatch(
            $importer::class,
            $path,
            $this->optionsFromRequest($request)->toArray(),
            $mapping,
            $user !== null ? $user::class : null,
            $user?->getKey(),
            // The worker has no request — capture tenant/user context now.
            $importer->context($request),
        );

        // Use the connection's default queue unless the importer pins a specific
        // one — config('queue.default') is the connection name, not a queue.
        if (($queue = $importer->queue()) !== null) {
            $pending->onQueue($queue);
        }

        return response()->json(['status' => 'queued']);
    }

    /**
     * Build the preview payload for a stored file.
     */
    protected function buildPreview(Importer $importer, string $path, ImportOptionsData $options): ImportPreviewData
    {
        [$absolutePath, $isTemp] = KinetixDisk::localReadablePath(KinetixDisk::privateName(), $path);

        try {
            $parsed = FileReader::read($absolutePath, $options, $this->previewRows);
            $total  = FileReader::countRows($absolutePath, $options);
        } finally {
            KinetixDisk::discardTemp($absolutePath, $isTemp);
        }

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
        if (! str_starts_with($path, $this->storageDirectory.'/') || str_contains($path, '..')) {
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
