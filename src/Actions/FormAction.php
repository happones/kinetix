<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tables\FormActionController;
use Happones\Kinetix\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An action that opens a modal hosting an arbitrary {@see Form}, then runs a
 * SERVER-SIDE handler through Kinetix's own signed endpoint once the submitted
 * state has been validated and dehydrated — not a host route the developer has
 * to secure by hand.
 *
 * The plain {@see Action} modal path is declarative: a `requiresConfirmation`
 * action only confirms, and anything richer (collecting a reason, a date, a
 * price) means wiring a bespoke controller and re-implementing scope + policy
 * there. A `FormAction` closes that gap the same way {@see BulkAction} does for
 * batch work — it moves the behaviour into a CLASS with a {@see handle()}
 * method and a {@see Form()} declaration.
 *
 * The table serialises the form's schema into the action's {@see ActionData}
 * (so the frontend can mount a `KinetixForm` in the modal) and seals the
 * action's name → class into a signed descriptor. The browser only ever sends
 * that descriptor, the action name, the optional record id and the submitted
 * form values. {@see FormActionController} then — exactly like the bulk/record
 * endpoints — decrypts the descriptor, resolves any record THROUGH the table's
 * own scope (an id outside it is refused), authorizes it against the host's
 * policy, reconstructs the SAME form class server-side to validate and dehydrate
 * the values, and only then hands the trusted state to `handle()`. The client
 * never names a class, a record or a validation rule.
 *
 * A `FormAction` works both as a RECORD action (the modal acts on the row that
 * opened it — `handle()` receives the record) and as a TOOLBAR action (no row —
 * `handle()` receives `null`), mirroring how `recordId` is optional on the wire.
 *
 * Subclass it, declare the schema in `form()` and implement `handle()`:
 *
 *     class Refund extends FormAction
 *     {
 *         protected function form(Form $form): Form
 *         {
 *             return $form->schema([
 *                 TextInput::make('amount')->numeric()->required(),
 *                 Textarea::make('reason')->required(),
 *             ]);
 *         }
 *
 *         public function handle(array $data, ?Model $record): void
 *         {
 *             $record?->refund($data['amount'], $data['reason']);
 *         }
 *     }
 *
 *     // name defaults to 'refund' (kebab of the class short name)
 *     $table->recordActions([Refund::make()->label('Refund')->icon('undo')]);
 *
 * All the fluent {@see Action} chrome (label, icon, color, modalHeading,
 * modalSubmitActionLabel, authorize/visible) still applies.
 */
abstract class FormAction extends Action
{
    /**
     * Create the action. Defaults the name to {@see defaultName()} (which a
     * subclass may override) so `Refund::make()` just works; pass a name to
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
     * Defaults to the kebab-cased short class name (`Refund` → `refund`);
     * override for a custom default.
     */
    protected static function defaultName(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();

        return (string) str($short)->kebab();
    }

    /**
     * Declare the modal's form schema. Override this to return the given
     * {@see Form} configured with the fields the action collects — it is the
     * SAME declaration the table serialises to the client and the controller
     * reconstructs server-side to validate against, so the two can never drift.
     *
     * The optional `$record` lets a record action tailor the schema to the row
     * it opened on (prefilled defaults, conditional fields); it is null for a
     * toolbar action.
     */
    protected function form(Form $form, ?Model $record = null): Form
    {
        return $form;
    }

    /**
     * Build the action's {@see Form}, optionally bound to a record. Shared by
     * {@see toData()} (serialise the schema for the client) and
     * {@see FormActionController} (reconstruct it to validate the submission),
     * so both see the exact same schema from the single {@see Form()} override.
     */
    public function getForm(?Model $record = null): Form
    {
        $form = $record !== null ? Form::make($record) : Form::make();

        return $this->form($form, $record)->operation($this->getName());
    }

    /**
     * Run the action with the already-validated, dehydrated form state and the
     * resolved record (null for a toolbar action). Implement the behaviour
     * here; the state is the trusted output of the SAME form class, never the
     * raw request — never re-read values off the request.
     *
     * @param array<string, mixed> $data
     */
    abstract public function handle(array $data, ?Model $record): void;

    /**
     * Marks this action as one the signed form endpoint runs server-side, so
     * {@see Table} seals its name → class and the
     * frontend opens a modal-with-form routed to `kinetix.tables.form-action`
     * instead of a host URL/event.
     */
    public function isFormAction(): bool
    {
        return true;
    }
}
