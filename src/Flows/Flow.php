<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bot flow: what starts it (`trigger`), the lines it runs on (`channel_ids`,
 * null for every line), the graph being edited (`draft`) and the published
 * version conversations run on.
 *
 * @property int|string                                                                  $id
 * @property int|string|null                                                             $team_id
 * @property string                                                                      $name
 * @property string                                                                      $status
 * @property array{type: string, keywords?: list<string>, match?: string}                $trigger
 * @property list<int|string>|null                                                       $channel_ids
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $draft
 * @property int|string|null                                                             $published_version_id
 * @property int                                                                         $priority
 * @property Carbon|null                                                                 $updated_at
 * @property FlowVersion|null                                                            $publishedVersion
 */
class Flow extends Model
{
    public const STATUSES = ['draft', 'active', 'inactive'];

    protected $table = 'kinetix_flows';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger'     => 'array',
            'channel_ids' => 'array',
            'draft'       => 'array',
            'priority'    => 'integer',
        ];
    }

    /**
     * @return HasMany<FlowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    /**
     * @return BelongsTo<FlowVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'published_version_id');
    }

    /**
     * @return HasMany<FlowRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(FlowRun::class);
    }

    public function runsOn(int|string $channelId): bool
    {
        return $this->channel_ids === null || $this->channel_ids === [] || in_array($channelId, $this->channel_ids, false);
    }

    /** Whether the draft differs from what's published. */
    public function hasUnpublishedChanges(): bool
    {
        return $this->publishedVersion === null || $this->publishedVersion->graph != $this->draft;
    }
}
