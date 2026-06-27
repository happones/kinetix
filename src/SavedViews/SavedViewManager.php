<?php

declare(strict_types=1);

namespace Happones\Kinetix\SavedViews;

use Happones\Kinetix\Data\SavedViewData;
use Illuminate\Database\Eloquent\Model;

/**
 * Lists, creates, updates and deletes a user's saved table views for a given
 * view key, and manages which one is their default.
 */
class SavedViewManager
{
    /**
     * The user's saved views for a table (default first, then by name).
     *
     * @return array<int, SavedViewData>
     */
    public function for(Model $user, string $viewKey, int|string|null $teamId = null): array
    {
        return SavedView::query()
            ->where('user_id', $user->getKey())
            ->where('view_key', $viewKey)
            ->where('team_id', $teamId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(static fn (SavedView $v): SavedViewData => SavedViewData::fromModel($v))
            ->all();
    }

    /**
     * @param array<string, mixed> $state
     */
    public function create(Model $user, string $viewKey, string $name, array $state, bool $isDefault = false, int|string|null $teamId = null): SavedView
    {
        $view = SavedView::query()->create([
            'user_id'    => $user->getKey(),
            'team_id'    => $teamId,
            'view_key'   => $viewKey,
            'name'       => $name,
            'state'      => $state,
            'is_default' => $isDefault,
        ]);

        if ($isDefault) {
            $this->makeDefault($user, $view, $teamId);
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function update(SavedView $view, string $name, array $state): SavedView
    {
        $view->update(['name' => $name, 'state' => $state]);

        return $view;
    }

    public function delete(SavedView $view): void
    {
        $view->delete();
    }

    /**
     * Mark a view as the user's default for its key (clearing the others).
     */
    public function makeDefault(Model $user, SavedView $view, int|string|null $teamId = null): void
    {
        SavedView::query()
            ->where('user_id', $user->getKey())
            ->where('view_key', $view->view_key)
            ->where('team_id', $teamId)
            ->whereKeyNot($view->getKey())
            ->update(['is_default' => false]);

        $view->update(['is_default' => true]);
    }

    /**
     * Find one of the user's own views by id (for update/delete/default).
     */
    public function ownedBy(Model $user, int|string $id): ?SavedView
    {
        return SavedView::query()
            ->where('user_id', $user->getKey())
            ->whereKey($id)
            ->first();
    }
}
