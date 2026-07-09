<?php

declare(strict_types=1);

namespace Happones\Kinetix\Pdf;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Endpoints behind `<KinetixPdfTemplate>`: the template descriptor (fields +
 * settings), settings persistence, the live HTML preview (with unsaved query
 * overrides) and the PDF download. Gated by `viewKinetixPdf` (local-only by
 * default — define the gate in production).
 */
class PdfTemplateController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixPdf');

        $templates = collect(app(PdfTemplateRegistry::class)->all())
            ->map(static fn (string $class, string $key): array => [
                'key'   => $key,
                'label' => $class::make()->label(),
            ])
            ->values();

        return response()->json(['templates' => $templates]);
    }

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixPdf');

        return response()->json($this->template($request)->toData());
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixPdf');

        $template = $this->template($request);

        PdfTemplateSetting::put($template::key(), $this->settingsFrom($request, $template));

        return response()->json($template->toData());
    }

    /**
     * Live HTML preview with sample data; query params override the stored
     * settings so the iframe reflects unsaved changes.
     */
    public function preview(Request $request): Response
    {
        Gate::authorize('viewKinetixPdf');

        $template = $this->template($request);

        return response(
            $template->render(null, $this->settingsFrom($request, $template)),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * PDF download of the sample document with the current (possibly unsaved)
     * settings — same overrides as the preview.
     */
    public function download(Request $request): Response
    {
        Gate::authorize('viewKinetixPdf');

        $template = $this->template($request);

        return response(
            $template->pdf(null, $this->settingsFrom($request, $template)),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$template::key().'.pdf"',
            ],
        );
    }

    protected function template(Request $request): PdfTemplate
    {
        $template = app(PdfTemplateRegistry::class)->get((string) $request->route('template'));

        abort_if($template === null, 404);

        return $template;
    }

    /**
     * Extract only the DECLARED fields from the request, cast to their native
     * types — undeclared keys can never reach the store or the renderer.
     *
     * @return array<string, mixed>
     */
    protected function settingsFrom(Request $request, PdfTemplate $template): array
    {
        $settings = [];

        foreach ($template->fields() as $field) {
            if ($request->has($field->name)) {
                $settings[$field->name] = $field->cast($request->input($field->name));
            }
        }

        return $settings;
    }
}
