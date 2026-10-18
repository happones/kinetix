<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved answer the team inserts in the inbox by typing `/shortcut`. Its
 * body may use `{{contact.name}}`, `{{contact.key}}`, `{{agent.name}}` and
 * `{{line.name}}`, filled in where it's inserted.
 *
 * @property int|string      $id
 * @property int|string|null $team_id
 * @property string          $shortcut
 * @property string          $body
 * @property int|string|null $created_by
 */
class QuickReply extends Model
{
    protected $table = 'kinetix_quick_replies';

    protected $guarded = [];

    /**
     * @return array{id: int|string, shortcut: string, body: string}
     */
    public function toPayload(): array
    {
        return ['id' => $this->getKey(), 'shortcut' => $this->shortcut, 'body' => $this->body];
    }
}
