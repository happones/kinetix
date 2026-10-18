<?php

declare(strict_types=1);

namespace Happones\Kinetix\Inbox;

use Happones\Kinetix\Channels\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A line talking with one contact — a WhatsApp number, a Telegram chat. The
 * contact may be linked to the app's own model (`contactable`) by
 * `kinetix.inbox.contact_resolver`.
 *
 * @property int|string      $id
 * @property int|string|null $team_id
 * @property int|string      $channel_id
 * @property string          $contact_key
 * @property string|null     $contact_name
 * @property bool            $is_group
 * @property int|string|null $assigned_to
 * @property int|string|null $last_message_id
 * @property Carbon|null     $last_message_at
 * @property Carbon|null     $archived_at
 * @property Channel         $channel
 */
class Conversation extends Model
{
    protected $table = 'kinetix_conversations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_group'        => 'boolean',
            'last_message_at' => 'datetime',
            'archived_at'     => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    /**
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'last_message_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The name to show: the contact's own, else their number or chat id. */
    public function displayName(): string
    {
        return $this->contact_name ?: $this->contact_key;
    }
}
