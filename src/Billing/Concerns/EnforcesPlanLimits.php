<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing\Concerns;

use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Billing\Exceptions\PlanLimitExceededException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Enforce a plan's `usage.*` limit when CREATING records of this model:
 *
 *     class Project extends Model
 *     {
 *         use EnforcesPlanLimits;   // checks features.usage.projects
 *     }
 *
 * On `creating`, the billable's plan limit for {@see planLimitKey()} is
 * compared against {@see planLimitQuery()}'s count — at the limit, a
 * {@see PlanLimitExceededException} (403) aborts the save. An unlimited plan
 * (no `usage.*` value), an unresolvable billable, or a billable without the
 * HasPlan trait all skip the check entirely, so the model keeps working in
 * billing-less environments — and the COUNT only runs when a limit exists.
 */
trait EnforcesPlanLimits
{
    public static function bootEnforcesPlanLimits(): void
    {
        static::creating(function (Model $model): void {
            /** @var Model&EnforcesPlanLimits $model */
            $model->enforcePlanLimit();
        });
    }

    /**
     * Run the limit check now (also callable manually, e.g. before showing a
     * "new record" form). Throws when the limit is reached.
     */
    public function enforcePlanLimit(): void
    {
        $billable = $this->planLimitBillable();

        if (! $billable instanceof Model || ! method_exists($billable, 'planLimit')) {
            return;
        }

        $limit = $billable->planLimit($this->planLimitKey());

        if ($limit === null) {
            return;
        }

        if ($this->planLimitQuery($billable)->count() >= $limit) {
            throw new PlanLimitExceededException($this->planLimitKey(), $limit);
        }
    }

    /**
     * The `features.usage.*` key this model consumes. Defaults to the plural
     * snake-cased model name (`Project` → `projects`).
     */
    public function planLimitKey(): string
    {
        return str(class_basename(static::class))->snake()->plural()->toString();
    }

    /**
     * The billable whose plan rules this creation. Defaults to the same
     * resolution every billing surface uses (`kinetix.billing.billable` /
     * `resolve_billable` / team context); null skips the check.
     */
    protected function planLimitBillable(): ?Model
    {
        try {
            return BillingManager::resolve()->billable();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The records counted against the limit. Defaults to this model narrowed
     * by the billable's conventional foreign key (`team_id` for a Team
     * billable, `user_id` for a User) whenever the creating record carries
     * that attribute — override for custom ownership shapes.
     *
     * @return Builder<Model>
     */
    protected function planLimitQuery(Model $billable): Builder
    {
        $query      = static::query();
        $foreignKey = str(class_basename($billable::class))->snake()->toString().'_id';

        if (array_key_exists($foreignKey, $this->getAttributes())) {
            $query->where($foreignKey, $billable->getKey());
        }

        return $query;
    }
}
