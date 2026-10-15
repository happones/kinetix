<?php

declare(strict_types=1);

namespace Happones\Kinetix\Wizards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service wizard completion endpoints. A user marks their own wizard as
 * complete (e.g. from <KinetixWizard> on finish), which releases the
 * `kinetix.wizard:<slug>` gate.
 */
class WizardController
{
    public function __construct(protected WizardManager $manager) {}

    public function status(Request $request): JsonResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the slug.
        $slug = (string) $request->route('slug');

        return response()->json([
            'slug'      => $slug,
            'completed' => $this->manager->hasCompleted($this->user($request), $slug),
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        // By name: under the team prefix (`{current_team}`) a positional
        // argument would receive the team, not the slug.
        $slug = (string) $request->route('slug');

        // A gate your data decides isn't opened by asking: save the data.
        abort_if($this->manager->completesFromData($slug), 403, __('kinetix.wizard_completes_from_data'));

        $this->manager->complete($this->user($request), $slug);

        return response()->json(['status' => 'success', 'slug' => $slug]);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }
}
