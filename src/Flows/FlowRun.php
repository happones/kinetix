<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Happones\Kinetix\Inbox\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A flow running in a conversation: the node it's on, its variables, and
 * whether it's working, waiting for a reply or a delay, or done.
 *
 * @property int|string           $id
 * @property int|string           $flow_id
 * @property int|string           $flow_version_id
 * @property int|string           $conversation_id
 * @property FlowRunStatus        $status
 * @property string|null          $current_node
 * @property array<string, mixed> $variables
 * @property int                  $attempts
 * @property Carbon|null          $wait_until
 * @property string|null          $error
 * @property Carbon|null          $finished_at
 * @property Flow                 $flow
 * @property FlowVersion          $version
 * @property Conversation         $conversation
 */
class FlowRun extends Model
{
    protected $table = 'kinetix_flow_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'      => FlowRunStatus::class,
            'variables'   => 'array',
            'attempts'    => 'integer',
            'wait_until'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Flow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /**
     * @return BelongsTo<FlowVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'flow_version_id');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
