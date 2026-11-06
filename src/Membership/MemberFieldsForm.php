<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Forms\Components\Component;
use Happones\Kinetix\Forms\Components\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\MessageBag;
use ReflectionProperty;

/**
 * Runs a host's {@see MemberFields} through the Kinetix forms engine, for the
 * membership controller.
 *
 * @internal
 */
final class MemberFieldsForm
{
    /**
     * The form for one operation, filled with `$values`: only those of the
     * fields shown to this actor (see {@see MemberForm}), so a hidden field's
     * value or a key the schema does not know never travels to the browser.
     * Every Select-like field is held to its own options, and the form knows
     * the user model so a `relationship()` select resolves.
     *
     * @param array<string, mixed> $values
     */
    public static function build(MemberFields $fields, MemberFieldsContext $context, array $values = []): MemberForm
    {
        $operation = $context->operation === MemberFieldsOperation::Provision ? 'create' : 'edit';
        $schema    = $fields->schema($context);
        $form      = MemberForm::make()
            ->schema($schema)
            ->operation($operation)
            ->strictOptions()
            ->restrictTo(self::visibleNames($schema, $operation));

        $model = (string) config('kinetix.membership.user_model', 'App\\Models\\User');

        if (class_exists($model)) {
            $form->model($model);
        }

        return $form->fill(array_intersect_key($values, array_flip($form->shownNames())));
    }

    /**
     * Names of the fields shown for this operation: not hidden by
     * `hidden()`/`visible()`, `hiddenOn()` or `visibleOn()`, nor inside a
     * layout (Section, Grid, Fieldset, Tabs…) that is. A field a client-side
     * condition hides is still among them.
     *
     * @param  array<int, Component> $components
     * @return array<int, string>
     */
    public static function visibleNames(array $components, string $operation): array
    {
        $names = [];

        foreach ($components as $component) {
            if ($component->isHidden($operation)) {
                continue;
            }

            if ($component instanceof Field) {
                $names[] = $component->getName();

                continue;
            }

            $children = property_exists($component, 'schema')
                ? (new ReflectionProperty($component, 'schema'))->getValue($component)
                : null;

            if (is_array($children)) {
                array_push($names, ...self::visibleNames($children, $operation));
            }
        }

        return $names;
    }

    /**
     * What an edit stores: the previous values, with the shown fields replaced
     * by the submitted state. A field hidden from this actor, and a key the
     * schema does not know, keep what they had; a shown field a client-side
     * condition emptied is cleared.
     *
     * @param  array<string, mixed> $previous
     * @param  array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function merge(MemberForm $form, array $previous, array $state): array
    {
        $shown = array_flip($form->shownNames());

        return array_diff_key($previous, $shown) + array_intersect_key($state, $shown);
    }

    /**
     * Values in a stable shape (keys sorted, lists of scalars sorted) so two
     * states can be compared for a real change.
     *
     * @param  array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public static function normalize(array $values): array
    {
        $values = array_map(static fn (mixed $value): mixed => is_array($value) ? self::normalize($value) : $value, $values);

        if (! array_is_list($values)) {
            ksort($values);

            return $values;
        }

        if (array_filter($values, static fn (mixed $value): bool => is_array($value)) === []) {
            sort($values, SORT_STRING);
        }

        return $values;
    }

    /**
     * Validate a submission. Returns what may be stored (the schema's own
     * fields, hidden and disabled ones enforced) and the errors keyed
     * `fields.<name>`; the caller merges them with its own and throws once.
     *
     * @param  array<string, mixed>                          $input
     * @return array{0: array<string, mixed>, 1: MessageBag}
     */
    public static function validate(MemberForm $form, array $input): array
    {
        $validator = $form->makeValidator($input);

        if ($validator->fails()) {
            $errors = new MessageBag;

            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) {
                    $errors->add('fields.'.$key, $message);
                }
            }

            return [[], $errors];
        }

        return [$form->getState($input), new MessageBag];
    }

    /**
     * What the edit form opens with: the pending member's stored values, or
     * an active member's, read back from the host.
     *
     * @return array<string, mixed>
     */
    public static function currentValues(MemberFields $fields, MemberProvision $provision, ?Model $user, MemberFieldsContext $context): array
    {
        if ($provision->status !== MemberProvisionStatus::Active) {
            return $provision->fields ?? [];
        }

        abort_if($user === null, 422, __('kinetix.members_account_missing'));

        return $fields->values($user, $context);
    }
}
