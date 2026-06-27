<?php

declare(strict_types=1);

namespace Happones\Kinetix\Accessibility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service: each user reads and updates only their own accessibility
 * preferences.
 */
class AccessibilityController
{
    public function __construct(protected AccessibilityManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->manager->for($this->user($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reducedMotion'  => ['sometimes', 'boolean'],
            'highContrast'   => ['sometimes', 'boolean'],
            'textSize'       => ['sometimes', 'in:normal,large,x-large'],
            'underlineLinks' => ['sometimes', 'boolean'],
            'enhancedFocus'  => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->manager->update($this->user($request), $validated));
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
