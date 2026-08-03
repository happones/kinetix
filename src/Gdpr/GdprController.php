<?php

declare(strict_types=1);

namespace Happones\Kinetix\Gdpr;

use Happones\Kinetix\Gdpr\Jobs\GdprExportJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service GDPR endpoints: a user exports their own data (queued, delivered
 * via notification) and deletes/anonymizes their own account. Deletion is gated
 * by a password re-entry when `kinetix.gdpr.require_password` is on.
 */
class GdprController
{
    public function __construct(protected GdprManager $manager) {}

    public function export(Request $request): JsonResponse
    {
        $user = $this->user($request);

        GdprExportJob::dispatch($user::class, $user->getKey());

        return response()->json(['status' => 'queued']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (config('kinetix.gdpr.require_password', true)) {
            $hash = method_exists($user, 'getAuthPassword')
                ? (string) $user->getAuthPassword()
                : (string) $user->getAttribute('password');

            abort_unless(
                $hash !== '' && Hash::check((string) $request->input('password', ''), $hash),
                422,
                (string) __('kinetix.gdpr_password_incorrect'),
            );
        }

        $this->manager->purge($user);

        // End the session so the deleted/anonymized account is logged out.
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'status'   => 'success',
            'redirect' => (string) config('kinetix.gdpr.redirect', '/'),
        ]);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
