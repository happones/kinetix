<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

/**
 * Row (or bulk) action for a relation manager's BelongsToMany table: detaches
 * the record(s) from the parent — pivot rows only, the related records
 * themselves are never deleted. Confirms first by default. The relation
 * manager wires the browser event to its signed descriptor automatically, so
 * plain `DetachAction::make()` inside the manager's `table()` is enough.
 */
class DetachAction extends Action
{
    public static function make(string $name = 'detach'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'detach')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.detach'))
            ->icon('unlink')
            ->color('danger')
            ->requiresConfirmation((string) __('kinetix.detach_confirm'));
    }

    /**
     * Called by RelationManager::toData() so the manager's listener knows the
     * event is for it (the clicked record / selected ids ride in the detail).
     */
    public function forRelationship(string $relationship): static
    {
        return $this->dispatch('detach-relation', ['relationship' => $relationship]);
    }
}
