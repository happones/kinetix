<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A published, frozen graph of a flow. Runs keep the version they started on.
 *
 * @property int|string                                                                  $id
 * @property int|string                                                                  $flow_id
 * @property int                                                                         $number
 * @property array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
 * @property Carbon|null                                                                 $created_at
 * @property Flow                                                                        $flow
 */
class FlowVersion extends Model
{
    protected $table = 'kinetix_flow_versions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['graph' => 'array', 'number' => 'integer'];
    }

    /**
     * @return BelongsTo<Flow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
