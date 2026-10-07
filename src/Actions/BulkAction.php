<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Tables\BulkActionController;
use Happones\Kinetix\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A bulk action whose work runs SERVER-SIDE through Kinetix's own signed
 * endpoint, not a host route the developer has to secure by hand.
 *
 * The plain {@see Action} bulk path is declarative — it POSTs the selected
 * `ids` to a URL (`inertiaVisit`/`request`) or fires a browser event, and the
 * host is fully responsible for scoping those ids to the table and authorizing
 * each one. That's flexible, but every host that forgets a check leaks: in a
 * multi-tenant app a crafted `ids` payload can touch rows the user never had
 * in front of them.
 *
 * A `BulkAction` closes that gap by moving the work into a CLASS with a
 * {@see handle()} method. The table seals the action's name → class into a
 * signed descriptor; the browser only ever sends that descriptor, the action
 * name and the selected ids. {@see BulkActionController}
 * then — exactly like cell-update/reorder — decrypts the descriptor, resolves
 * the ids THROUGH the table's own scope (ids outside it are dropped, never
 * 404ing the whole batch), authorizes each surviving record against the host's
 * policy, and only then hands the authorized collection to `handle()`. The
 * host writes the behaviour; Kinetix guarantees the records are in-scope and
 * allowed before it runs.
 *
 * Subclass it and implement `handle()`:
 *
 *     class MarkPaid extends BulkAction
 *     {
 *         public function handle(Collection $records): void
 *         {
 *             $records->each->markPaid();
 *         }
 *     }
 *
 *     // name defaults to 'mark-paid' (kebab of the class short name)
 *     $table->bulkActions([MarkPaid::make()->label('Mark paid')->icon('check')]);
 *
 * All the fluent {@see Action} chrome (label, icon, color, confirmation modal,
 * authorize/visible) still applies.
 */
abstract class BulkAction extends Action
{
    /**
     * Create the action. Defaults the name to {@see defaultName()} (which a
     * subclass may override) so `MarkPaid::make()` just works; pass a name to
     * override it per instance.
     */
    public static function make(?string $name = null): static
    {
        $instance        = new static($name ?? static::defaultName());
        $instance->label = ucfirst($instance->getName());

        return $instance;
    }

    /**
     * The action name used when {@see make()} is called with no argument.
     * Defaults to the kebab-cased short class name (`MarkPaid` → `mark-paid`);
     * override for a custom default.
     */
    protected static function defaultName(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();

        return (string) str($short)->kebab();
    }

    /**
     * Run the action over the records the controller has already scoped to the
     * table and authorized one by one. Implement the behaviour here; never
     * re-query for ids off the request — the collection is the trusted set.
     *
     * @param Collection<int, Model> $records
     */
    abstract public function handle(Collection $records): void;

    /**
     * Marks this action as one the signed bulk endpoint runs server-side, so
     * {@see Table} seals its name → class and the
     * frontend routes it to `kinetix.tables.bulk-action` instead of a host URL.
     */
    public function isSecure(): bool
    {
        return true;
    }
}
