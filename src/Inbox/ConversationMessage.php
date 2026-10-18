<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a conversation: from the contact, from the team, or an
 * internal note the contact never sees. Its file (when it has one) lives on
 * `kinetix.inbox.disk`.
 *
 * @property int|string                $id
 * @property int|string                $conversation_id
 * @property MessageDirection          $direction
 * @property string                    $type
 * @property string|null               $body
 * @property string|null               $media_path
 * @property string|null               $media_mime
 * @property string|null               $media_name
 * @property int|null                  $media_size
 * @property string|null               $media_ref
 * @property MessageStatus             $status
 * @property string|null               $error
 * @property string|null               $external_id
 * @property int|string|null           $reply_to_id
 * @property int|string|null           $author_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null               $sent_at
 * @property Carbon|null               $created_at
 * @property Conversation              $conversation
 * @property ConversationMessage|null  $replyTo
 */
class ConversationMessage extends Model
{
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    protected $table = 'kinetix_conversation_messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'status'    => MessageStatus::class,
            'meta'      => 'array',
            'sent_at'   => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function hasMedia(): bool
    {
        return in_array($this->type, self::MEDIA_TYPES, true);
    }
}
