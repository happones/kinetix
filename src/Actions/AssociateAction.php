<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

/**
 * Toolbar action for a relation manager's HasMany/MorphMany table: opens the
 * associate modal (search + multi-select of records not yet owned by any
 * parent) and re-parents the chosen records onto this manager's parent —
 * Filament's AssociateAction. The relation manager wires the browser event's
 * `relationship` automatically when serializing, so plain
 * `AssociateAction::make()` inside the manager's `table()` is all it takes.
 */
class AssociateAction extends Action
{
    public static function make(string $name = 'associate'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'associate')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.associate'))
            ->icon('link')
            ->color('gray');
    }

    /**
     * Called by RelationManager::toData() so the modal knows which manager
     * (and descriptor) the event belongs to.
     */
    public function forRelationship(string $relationship): static
    {
        return $this->dispatch('open-associate', ['relationship' => $relationship]);
    }
}
