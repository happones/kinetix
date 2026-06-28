<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Self-service CRUD + preview/test for editable mail templates. Gated by the
 * `viewKinetixMail` ability (defaults to allow in `local`).
 */
class MailTemplateController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        return response()->json([
            'templates' => MailTemplate::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $template = MailTemplate::create($this->validated($request));

        return response()->json(['template' => $template], 201);
    }

    public function update(Request $request, string $template): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $model = MailTemplate::query()->findOrFail($template);
        $model->update($this->validated($request, (int) $model->id));

        return response()->json(['template' => $model]);
    }

    public function destroy(string $template): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        MailTemplate::query()->findOrFail($template)->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * Render arbitrary (unsaved) editor content with sample data — live preview.
     */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $validated = $request->validate([
            'subject' => ['nullable', 'string'],
            'body'    => ['nullable', 'string'],
            'format'  => ['nullable', Rule::in(['markdown', 'html'])],
            'data'    => ['nullable', 'array'],
        ]);

        $template = new MailTemplate([
            'subject' => $validated['subject'] ?? '',
            'body'    => $validated['body']    ?? '',
            'format'  => $validated['format']  ?? 'markdown',
        ]);

        return response()->json($template->render((array) ($validated['data'] ?? [])));
    }

    public function test(Request $request, string $template): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $model = MailTemplate::query()->findOrFail($template);
        $email = (string) $request->validate(['email' => ['required', 'email']])['email'];

        KinetixMail::test($model->key, $email);

        return response()->json(['status' => 'success']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key'                => ['required', 'string', 'max:255', Rule::unique('kinetix_mail_templates', 'key')->ignore($ignoreId)],
            'name'               => ['required', 'string', 'max:255'],
            'subject'            => ['required', 'string', 'max:255'],
            'body'               => ['required', 'string'],
            'format'             => ['required', Rule::in(['markdown', 'html'])],
            'variables'          => ['nullable', 'array'],
            'variables.*.key'    => ['required', 'string'],
            'variables.*.label'  => ['nullable', 'string'],
            'variables.*.sample' => ['nullable', 'string'],
            'enabled'            => ['boolean'],
        ]);
    }
}
