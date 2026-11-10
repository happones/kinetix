<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationPreferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service notification preferences: each user reads and updates only their
 * own type × channel matrix.
 */
class NotificationPreferenceController
{
    public function __construct(
        protected NotificationPreferenceManager $manager,
        protected NotificationTypeRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->manager->for($this->user($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);

        // Only a channel the type is delivered on has a switch.
        $type = $request->input('type');

        $validated = $request->validate([
            'type'    => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
            'channel' => ['required', 'string', Rule::in(is_string($type) ? $this->manager->channelsOf($type) : [])],
            'enabled' => ['required', 'boolean'],
        ]);

        // A channel the type makes mandatory (in code) cannot be switched off.
        if (! $validated['enabled'] && $this->manager->isMandatory($validated['type'], $validated['channel'])) {
            throw ValidationException::withMessages(['enabled' => __('kinetix::kinetix.notification_preference_mandatory')]);
        }

        $this->manager->update($user, $validated['type'], $validated['channel'], $validated['enabled']);

        return response()->json(['status' => 'success']);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
