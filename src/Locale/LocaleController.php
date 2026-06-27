<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service locale switch: persist the selected language for the current user
 * (or guest session). Works without authentication so it can be used on the
 * login screen or a setup wizard.
 */
class LocaleController
{
    public function __construct(protected LocaleManager $manager) {}

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string'],
        ]);

        $user = $request->user();

        abort_unless(
            $this->manager->set($validated['locale'], $user instanceof Model ? $user : null),
            422,
            'Unsupported locale.',
        );

        return response()->json([
            'locale' => $this->manager->current(),
        ]);
    }
}
