<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
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
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        // The team's own templates plus the global defaults, with an override
        // hiding the default it replaces (same key).
        $templates = MailTemplate::query()
            ->forCurrentTeamOrGlobal()
            ->orderBy('name')
            ->get()
            ->sortBy(fn (MailTemplate $template): int => $template->isGlobal() ? 1 : 0)
            ->unique('key')
            ->sortBy('name')
            ->values();

        return response()->json([
            'templates' => $templates,
            'test'      => [
                'recipients' => $this->testRecipients(),
                'email'      => $this->actorEmail($request),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $template = MailTemplate::create([
            ...$this->validated($request),
            ...MailTemplate::teamAttributes(),
        ]);

        return response()->json(['template' => $template], 201);
    }

    /**
     * Editing a **global** template from inside a team does not rewrite the
     * platform default for every tenant — it forks it into an override owned by
     * that team (copy-on-write). Outside a team scope the global row is edited
     * in place, which is how a platform admin maintains the defaults.
     */
    public function update(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $model  = $this->findTemplate($request);
        $teamId = MailTemplate::currentTeamId();

        if ($model->isGlobal() && $teamId !== null) {
            $override = MailTemplate::create([
                ...$this->validated($request),
                ...MailTemplate::teamAttributes(),
            ]);

            return response()->json(['template' => $override, 'forked' => true], 201);
        }

        $model->update($this->validated($request, (int) $model->id));

        return response()->json(['template' => $model]);
    }

    /**
     * Deleting a team's override reverts that team to the global default;
     * deleting the default itself requires platform scope.
     */
    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $model = $this->findTemplate($request);

        abort_if(
            $model->isGlobal() && MailTemplate::currentTeamId() !== null,
            403,
            'This is a global template. Delete it outside a team scope, or override it for this team instead.',
        );

        $model->delete();

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

    /**
     * Send the template to a test address. With `mail_templates.test_recipients`
     * = `'self'` (the default) it only goes to the acting user's own address
     * (`email` may be left out); another address is a 422 and nothing is
     * sent, so the endpoint can't mail strangers. `'any'` accepts any
     * address.
     */
    public function test(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixMail');

        $model = $this->findTemplate($request);

        if ($this->testRecipients() === 'any') {
            $email = (string) $request->validate(['email' => ['required', 'email']])['email'];
        } else {
            $email = $this->actorEmail($request);
            $given = $request->validate(['email' => ['nullable', 'string']])['email'] ?? null;

            if ($email === null || (is_string($given) && $given !== '' && strcasecmp(trim($given), $email) !== 0)) {
                return response()->json(['message' => __('kinetix.mail_test_self_only')], 422);
            }
        }

        KinetixMail::test($model->key, $email);

        return response()->json(['status' => 'success']);
    }

    /** @return 'self'|'any' */
    protected function testRecipients(): string
    {
        return config('kinetix.mail_templates.test_recipients', 'self') === 'any' ? 'any' : 'self';
    }

    /** The acting user's own address, where `'self'` test emails go. */
    protected function actorEmail(Request $request): ?string
    {
        return $this->emailOf($request->user());
    }

    /** A user's address: the one password resets go to, else its `email`. */
    protected function emailOf(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $email = $user instanceof CanResetPassword ? $user->getEmailForPasswordReset() : null;
        $email = $email !== null && $email !== '' ? $email : data_get($user, 'email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * A template the current tenant can see: its own, or a global default.
     * Another team's row is a 404 — its existence is not leaked.
     *
     * The id is read by route-parameter NAME, not from a positional argument:
     * with teams on the routes gain a leading `{current_team}`, which would
     * otherwise be injected as the template id.
     */
    protected function findTemplate(Request $request): MailTemplate
    {
        return MailTemplate::query()
            ->forCurrentTeamOrGlobal()
            ->findOrFail($request->route('template'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        // Keys are unique per tenant, not globally: a team's override reuses the
        // global template's key on purpose.
        $unique = Rule::unique('kinetix_mail_templates', 'key')
            ->where('team_id', MailTemplate::currentTeamId())
            ->ignore($ignoreId);

        return $request->validate([
            'key'                => ['required', 'string', 'max:255', $unique],
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
