<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A billing plan. Features are a nested JSON structure (usage limits + capability
 * flags) resolved via dot-notation, e.g. `usage.seats`, `capabilities.api`.
 *
 * @property string                    $name
 * @property string                    $slug
 * @property string|null               $description
 * @property numeric-string|float|null $monthly_price
 * @property numeric-string|float|null $yearly_price
 * @property string|null               $stripe_monthly_price_id
 * @property string|null               $stripe_yearly_price_id
 * @property array<string, mixed>|null $features
 * @property array<int, string>|null   $highlighted_features
 * @property bool                      $is_free
 * @property bool                      $is_featured
 * @property bool                      $is_active
 * @property int                       $sort_order
 * @property int|null                  $trial_days
 */
class Plan extends Model
{
    protected $table = 'plans';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'monthly_price',
        'yearly_price',
        'stripe_monthly_price_id',
        'stripe_yearly_price_id',
        'features',
        'highlighted_features',
        'is_free',
        'is_featured',
        'is_active',
        'sort_order',
        'trial_days',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'features'             => 'array',
        'highlighted_features' => 'array',
        'is_free'              => 'boolean',
        'is_featured'          => 'boolean',
        'is_active'            => 'boolean',
        'monthly_price'        => 'decimal:2',
        'yearly_price'         => 'decimal:2',
        'sort_order'           => 'integer',
        'trial_days'           => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Plan $plan): void {
            if (empty($plan->slug) && ! empty($plan->name)) {
                $plan->slug = Str::slug($plan->name);
            }
        });

        // Every plan question is answered from {@see PlanCatalog}, so a write
        // through the model must drop it — otherwise a price or feature edit
        // would keep serving the old catalog for the rest of the request (and,
        // with `billing.cache.ttl` set, until the entry expired).
        static::saved(static function (): void {
            PlanCatalog::flush();
        });

        static::deleted(static function (): void {
            PlanCatalog::flush();
        });
    }

    /**
     * Bulk writes (`Plan::query()->update(...)`) fire no model events, so the
     * catalog is flushed from the builder instead.
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    public function newEloquentBuilder($query): PlanQueryBuilder
    {
        return new PlanQueryBuilder($query);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('monthly_price');
    }

    /**
     * Raw feature value at a dot-path (e.g. 'usage.seats'), or $default.
     */
    public function featureValue(string $path, mixed $default = null): mixed
    {
        return data_get($this->features, $path, $default);
    }

    /**
     * Whether the plan grants a feature: booleans as-is, arrays when non-empty,
     * everything else by truthiness (0 / null = denied).
     */
    public function canUseFeature(string $path): bool
    {
        $value = data_get($this->features, $path);

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return count($value) > 0;
        }

        return (bool) $value;
    }

    /**
     * Whether a usage count has reached the plan's limit at $path.
     * A null limit means unlimited.
     */
    public function hasReachedLimit(string $path, int $count): bool
    {
        $limit = data_get($this->features, $path);

        if ($limit === null) {
            return false;
        }

        return $count >= (int) $limit;
    }

    /**
     * How many units are left before the limit at $path is reached, floored at
     * zero. Returns null when the plan has no limit there (unlimited) — so
     * `remainingLimit(...) === null` means "never show a counter".
     */
    public function remainingLimit(string $path, int $count): ?int
    {
        $limit = data_get($this->features, $path);

        if ($limit === null) {
            return null;
        }

        return max(0, (int) $limit - $count);
    }

    public function priceFor(string $cycle = 'monthly'): ?float
    {
        $value = $cycle === 'yearly' ? $this->yearly_price : $this->monthly_price;

        return $value === null ? null : (float) $value;
    }

    /**
     * The Stripe price id for a cycle, or null when the plan has none.
     *
     * A blank column counts as "none": `''` is what a form or an import leaves
     * behind, and handing it to Stripe as a price id fails with an opaque API
     * error instead of the explicit "plan has no price for this cycle".
     */
    public function stripePriceId(string $cycle = 'monthly'): ?string
    {
        $priceId = $cycle === 'yearly' ? $this->stripe_yearly_price_id : $this->stripe_monthly_price_id;

        return filled($priceId) ? (string) $priceId : null;
    }

    public function isFree(): bool
    {
        if ((bool) $this->is_free) {
            return true;
        }

        return (float) $this->monthly_price <= 0.0;
    }
}
