<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

/**
 * Toolbar action for a relation manager's BelongsToMany table: opens the
 * attach modal (search + multi-select of not-yet-attached records). The
 * relation manager wires the browser event's `relationship` automatically
 * when serializing, so plain `AttachAction::make()` inside the manager's
 * `table()` is all it takes — the modal posts to the signed attach endpoint.
 */
class AttachAction extends Action
{
    public static function make(string $name = 'attach'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'attach')
    {
        parent::__construct($name);

        $this->label((string) __('kinetix.attach'))
            ->icon('link')
            ->color('gray');
    }

    /**
     * Called by RelationManager::toData() so the modal knows which manager
     * (and descriptor) the event belongs to.
     */
    public function forRelationship(string $relationship): static
    {
        return $this->dispatch('open-attach', ['relationship' => $relationship]);
    }
}
