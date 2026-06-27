<?php

declare(strict_types=1);

namespace Happones\Kinetix\SavedViews;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service saved table views: each user manages only their own presets,
 * scoped to a `view_key` (the table they belong to).
 */
class SavedViewController
{
    public function __construct(protected SavedViewManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['key' => ['required', 'string']]);

        return response()->json([
            'views' => $this->manager->for($this->user($request), (string) $request->query('key'), $this->teamId($request)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'key'        => ['required', 'string'],
            'name'       => ['required', 'string', 'max:120'],
            'state'      => ['array'],
            'is_default' => ['boolean'],
        ]);

        $this->manager->create(
            $user,
            $validated['key'],
            $validated['name'],
            $validated['state'] ?? [],
            (bool) ($validated['is_default'] ?? false),
            $this->teamId($request),
        );

        return response()->json([
            'views' => $this->manager->for($user, $validated['key'], $this->teamId($request)),
        ], 201);
    }

    public function update(Request $request, int|string $view): JsonResponse
    {
        $user  = $this->user($request);
        $model = $this->owned($user, $view);

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'state' => ['array'],
        ]);

        $this->manager->update($model, $validated['name'], $validated['state'] ?? $model->state);

        return response()->json([
            'views' => $this->manager->for($user, $model->view_key, $this->teamId($request)),
        ]);
    }

    public function destroy(Request $request, int|string $view): JsonResponse
    {
        $user  = $this->user($request);
        $model = $this->owned($user, $view);
        $key   = $model->view_key;

        $this->manager->delete($model);

        return response()->json([
            'views' => $this->manager->for($user, $key, $this->teamId($request)),
        ]);
    }

    public function makeDefault(Request $request, int|string $view): JsonResponse
    {
        $user  = $this->user($request);
        $model = $this->owned($user, $view);

        $this->manager->makeDefault($user, $model, $this->teamId($request));

        return response()->json([
            'views' => $this->manager->for($user, $model->view_key, $this->teamId($request)),
        ]);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }

    protected function owned(Model $user, int|string $id): SavedView
    {
        $view = $this->manager->ownedBy($user, $id);

        abort_if($view === null, 404);

        return $view;
    }

    protected function teamId(Request $request): int|string|null
    {
        if (! config('kinetix.teams', false)) {
            return null;
        }

        return $request->route('current_team');
    }
}
