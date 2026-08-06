<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

/**
 * Row (or bulk) action for a relation manager's HasMany/MorphMany table:
 * dissociates the record(s) from the parent by nulling the foreign key — the
 * related records themselves are never deleted (Filament's DissociateAction).
 * Confirms first by default. The relation manager wires the browser event to
 * its signed descriptor automatically, so plain `DissociateAction::make()`
 * inside the manager's `table()` is enough.
 */
class DissociateAction extends Action
{
    public static function make(string $name = 'dissociate'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'dissociate')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.dissociate'))
            ->icon('unlink')
            ->color('danger')
            ->requiresConfirmation((string) __('kinetix.dissociate_confirm'));
    }

    /**
     * Called by RelationManager::toData() so the manager's listener knows the
     * event is for it (the clicked record / selected ids ride in the detail).
     */
    public function forRelationship(string $relationship): static
    {
        return $this->dispatch('dissociate-relation', ['relationship' => $relationship]);
    }
}
