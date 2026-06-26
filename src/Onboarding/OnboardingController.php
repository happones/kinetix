<?php

declare(strict_types=1);

namespace Happones\Kinetix\Onboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service onboarding checklist endpoints. Each user reads and updates only
 * their own progress (no admin ability).
 */
class OnboardingController
{
    public function __construct(protected OnboardingManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->manager->for($this->user($request)));
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'step' => ['required', 'string'],
        ]);

        $this->manager->complete($this->user($request), $validated['step']);

        return response()->json($this->manager->for($this->user($request)));
    }

    public function dismiss(Request $request): JsonResponse
    {
        $this->manager->dismiss($this->user($request));

        return response()->json(['status' => 'success']);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
