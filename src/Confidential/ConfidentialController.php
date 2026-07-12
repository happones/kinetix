<?php

declare(strict_types=1);

namespace Happones\Kinetix\Confidential;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Unlocks/locks the current session's confidential-fields reveal window.
 */
class ConfidentialController
{
    public function __construct(protected ConfidentialManager $manager) {}

    public function unlock(Request $request): JsonResponse
    {
        Gate::authorize('revealKinetixConfidential');

        $request->validate(['password' => ['required', 'string']]);

        $unlocked = $this->manager->unlock((string) $request->input('password'));

        if (! $unlocked) {
            return response()->json([
                'unlocked' => false,
                'message'  => trans('kinetix.confidential_password_incorrect'),
            ], 422);
        }

        return response()->json(['unlocked' => true]);
    }

    public function lock(Request $request): JsonResponse
    {
        Gate::authorize('revealKinetixConfidential');

        $this->manager->lock();

        return response()->json(['unlocked' => false]);
    }
}
