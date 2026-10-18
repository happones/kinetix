<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * Where a flow's actions go: a real conversation (through the inbox), or the
 * editor's simulator, which only collects them.
 */
interface FlowOutbox
{
    /**
     * Say something to the contact, optionally offering options to pick.
     *
     * @param list<string> $options
     */
    public function say(string $text, array $options = []): void;

    /** Leave an internal note for the team. */
    public function note(string $text): void;

    /** Hand the conversation to someone (or leave it unassigned for anyone). */
    public function assign(int|string|null $userKey): void;
}
