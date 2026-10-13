<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Types;

use Happones\Kinetix\Support\Period;
use Happones\Kinetix\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Code shaped like a host app's, analysed by PHPStan (never run): it calls
 * the public API with a query of a concrete model, the way a host does. A
 * signature typed `Builder<Model>` rejects `Builder<Order>` — Builder's
 * template is invariant — and that only shows up in the host's analysis.
 */
final class HostUsage
{
    /**
     * @param  Builder<HostUsageOrder> $orders
     * @return Builder<HostUsageOrder>
     */
    public function scopeToAPeriod(Builder $orders): Builder
    {
        return Period::scope($orders, 'created_at', '30d');
    }

    /**
     * @param Builder<HostUsageOrder> $orders
     */
    public function tableOverAScopedQuery(Builder $orders): Table
    {
        Table::make($orders)->paginate($orders);

        return Table::make(HostUsageOrder::class)->scopedBy($orders);
    }
}

final class HostUsageOrder extends Model {}
