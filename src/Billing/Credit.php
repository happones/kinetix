<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A top-up credit balance for one metric key (e.g. purchased extra
 * `ai_messages`). Consumption beyond the plan's allowance draws the balance
 * down; credits are not period-scoped and persist until consumed. Managed
 * through {@see Concerns\HasMeteredUsage::addCredits()} / `consume()`.
 *
 * @property string $key
 * @property int    $balance
 */
class Credit extends Model
{
    protected $table = 'kinetix_credits';

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'balance'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['balance' => 'integer'];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
