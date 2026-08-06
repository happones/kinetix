<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Forms\Components\Component;

/**
 * Toolbar action for a relation manager's BelongsToMany table: opens the
 * attach modal (search + multi-select of not-yet-attached records). The
 * relation manager wires the browser event's `relationship` automatically
 * when serializing, so plain `AttachAction::make()` inside the manager's
 * `table()` is all it takes — the modal posts to the signed attach endpoint.
 */
class AttachAction extends Action
{
    /**
     * Pivot fields the attach modal renders below the record picker
     * (Filament's `AttachAction::form()`). Every field name must be a
     * `withPivot()` column — the manager throws at serialize time otherwise.
     *
     * @var array<int, Component>
     */
    protected array $form = [];

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
     * Pivot fields to collect when attaching (e.g. `TextInput::make('role')`).
     * The validated state is written to the pivot row of every record the
     * picker attaches.
     *
     * @param array<int, Component> $components
     */
    public function form(array $components): static
    {
        $this->form = $components;

        return $this;
    }

    /**
     * @return array<int, Component>
     */
    public function getForm(): array
    {
        return $this->form;
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
