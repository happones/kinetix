<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One consumption counter: billable + metric key + period ('YYYY-MM'
 * calendar month, or '' for a lifetime counter). Written exclusively through
 * {@see Concerns\HasMeteredUsage::consume()}, which does the plan/credit
 * accounting atomically.
 *
 * @property string $key
 * @property string $period
 * @property int    $used
 */
class UsageRecord extends Model
{
    protected $table = 'kinetix_usage';

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'period', 'used'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['used' => 'integer'];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
