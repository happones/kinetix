<?php

declare(strict_types=1);

namespace Happones\Kinetix\Dismissals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where `<KinetixAlert dismiss-mode="permanent">` reports a close, and where
 * re-opening it (`v-model:open` back to true) takes it back.
 */
class DismissalController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'regex:'.KinetixDismissals::KEY_PATTERN],
            // A close that comes back on its own: "remind me in 30 days".
            'minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5256000'],
        ]);

        $minutes = $validated['minutes'] ?? null;

        KinetixDismissals::dismiss(
            $this->user($request),
            $validated['key'],
            $minutes === null ? null : now()->addMinutes((int) $minutes),
        );

        return response()->json(['dismissed' => true]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        abort_unless(preg_match(KinetixDismissals::KEY_PATTERN, $key) === 1, 404);

        KinetixDismissals::restore($this->user($request), $key);

        return response()->json(['restored' => true]);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_unless($user instanceof Model, 401);

        return $user;
    }
}
