<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\NumberField;
use Happones\Kinetix\Forms\Components\Repeater;
use Happones\Kinetix\Forms\Components\TableRepeater;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Support\Get;
use Happones\Kinetix\Forms\Support\Set;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RdrOrder extends Model
{
    protected $table = 'rdr_orders';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}

class RdrForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Repeater::make('items')->schema([
                TextInput::make('name'),
                NumberField::make('price')->disabled(),
            ]),
        ];
    }
}

class RdrOtherForm extends RdrForm {}

class RdrTableForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            TableRepeater::make('items')->columns([
                TextInput::make('name'),
                NumberField::make('price')->disabled(),
            ]),
        ];
    }
}

class RdrConditionalForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Repeater::make('items')->schema([
                TextInput::make('name'),
                Toggle::make('locked'),
                NumberField::make('price')->disabledWhen('locked', true),
            ]),
        ];
    }
}

class RdrSavedForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Repeater::make('items')->schema([
                TextInput::make('name'),
                NumberField::make('price')->disabled()->savedWhenDisabled(),
            ]),
        ];
    }
}

class RdrOpenForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Repeater::make('items')->schema([TextInput::make('name'), NumberField::make('price')]),
        ];
    }
}

class RdrNestedForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            Repeater::make('items')->schema([
                TextInput::make('name'),
                Repeater::make('lines')->schema([NumberField::make('qty')->disabled()]),
            ]),
        ];
    }
}

class RdrHookForm extends Form
{
    protected function buildSchema(): array
    {
        return [
            TextInput::make('op')->live()->afterStateUpdated(function (Get $get, Set $set): void {
                $items = array_values((array) ($get('items') ?? []));

                if ($get('op') === 'reverse') {
                    $items = array_reverse($items);
                } elseif ($get('op') === 'drop') {
                    array_shift($items);
                } elseif ($get('op') === 'add') {
                    // Built from scratch, in FRONT: the same values as stored
                    // row 0, no key.
                    array_unshift($items, ['name' => 'a', 'price' => 10]);
                }

                $set('items', $items);
            }),
            Repeater::make('items')->schema([
                TextInput::make('name'),
                NumberField::make('price')->disabled(),
            ]),
        ];
    }
}

/**
 * A disabled field inside a repeater row is enforced on the server: a stored
 * row keeps its locked values (matched through a server-issued row key), a
 * row added in the browser gets the default.
 */
class RepeaterDisabledRowsTest extends TestCase
{
    private RdrOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rdr_orders', function (Blueprint $table) {
            $table->id();
            $table->json('items')->nullable();
        });

        $this->order = RdrOrder::create(['items' => [['name' => 'a', 'price' => 10], ['name' => 'b', 'price' => 20]]]);
    }

    public function test_edited_locked_values_are_restored_from_the_stored_rows(): void
    {
        $rows = $this->rendered(RdrForm::class);

        $rows[0]['price'] = 1;
        $rows[0]['name']  = 'A!';
        $rows[1]['price'] = 1;

        $this->assertSame([['name' => 'A!', 'price' => 10], ['name' => 'b', 'price' => 20]], $this->submit(RdrForm::class, $rows));
    }

    public function test_reordered_rows_keep_their_own_stored_values(): void
    {
        $rows = $this->rendered(RdrForm::class);
        $rows = [$rows[1], $rows[0]];

        $rows[0]['price'] = 99;
        $rows[1]['price'] = 99;

        $this->assertSame([['name' => 'b', 'price' => 20], ['name' => 'a', 'price' => 10]], $this->submit(RdrForm::class, $rows));
    }

    public function test_a_row_added_in_the_browser_gets_the_default(): void
    {
        $rows   = $this->rendered(RdrForm::class);
        $rows[] = ['name' => 'c', 'price' => 999];

        $this->assertSame(['name' => 'c', 'price' => null], $this->submit(RdrForm::class, $rows)[2]);
    }

    public function test_a_duplicated_key_counts_once(): void
    {
        $rows   = $this->rendered(RdrForm::class);
        $rows[] = ['name' => 'copy', 'price' => 999, Repeater::ROW_KEY => $rows[0][Repeater::ROW_KEY]];

        $this->assertSame(['name' => 'copy', 'price' => null], $this->submit(RdrForm::class, $rows)[2]);
    }

    public function test_a_forged_or_foreign_key_gets_the_default(): void
    {
        $foreign = $this->rendered(RdrOtherForm::class);
        $rows    = $this->rendered(RdrForm::class);

        $rows[0][Repeater::ROW_KEY] = '0.AAAAAAAAAAAAAAAAAAAAAA';
        $rows[0]['price']           = 5;
        $rows[1][Repeater::ROW_KEY] = $foreign[1][Repeater::ROW_KEY];
        $rows[1]['price']           = 5;

        $state = $this->submit(RdrForm::class, $rows);

        $this->assertNull($state[0]['price']);
        $this->assertNull($state[1]['price']);

        $other     = RdrOrder::create(['items' => $this->order->items]);
        $otherRows = RdrForm::make($other)->fill($other)->toArray()['data']['items'];
        $rows      = $this->rendered(RdrForm::class);
        $rows[0]   = [...$rows[0], Repeater::ROW_KEY => $otherRows[0][Repeater::ROW_KEY], 'price' => 5];

        $this->assertNull($this->submit(RdrForm::class, $rows)[0]['price']);
    }

    public function test_a_stale_key_gets_the_default(): void
    {
        $rows = $this->rendered(RdrForm::class);

        $this->order->update(['items' => [['name' => 'a', 'price' => 11], ['name' => 'b', 'price' => 20]]]);

        $rows[0]['price'] = 10;

        $this->assertNull($this->submit(RdrForm::class, $rows)[0]['price']);
    }

    public function test_saved_when_disabled_keeps_the_submitted_value(): void
    {
        $rows = $this->rendered(RdrSavedForm::class);

        $this->assertArrayNotHasKey(Repeater::ROW_KEY, $rows[0]);

        $rows[0]['price'] = 1;

        $this->assertSame(1, $this->submit(RdrSavedForm::class, $rows)[0]['price']);
    }

    public function test_a_condition_is_judged_per_row(): void
    {
        $this->order->update(['items' => [['name' => 'a', 'locked' => true, 'price' => 10], ['name' => 'b', 'locked' => false, 'price' => 20]]]);

        $rows             = $this->rendered(RdrConditionalForm::class);
        $rows[0]['price'] = 1;
        $rows[1]['price'] = 2;

        $state = $this->submit(RdrConditionalForm::class, $rows);

        $this->assertSame(10, $state[0]['price']);
        $this->assertSame(2, $state[1]['price']);
    }

    public function test_the_row_key_never_reaches_the_state(): void
    {
        $state = $this->submit(RdrForm::class, $this->rendered(RdrForm::class));

        $this->assertStringNotContainsString(Repeater::ROW_KEY, (string) json_encode($state));

        $dehydrated = Repeater::make('items')->dehydrate([['name' => 'x', Repeater::ROW_KEY => '0.x']]);
        $this->assertSame([['name' => 'x']], $dehydrated);
    }

    public function test_a_repeater_without_locked_fields_renders_no_keys(): void
    {
        $this->assertSame([['name' => 'a', 'price' => 10], ['name' => 'b', 'price' => 20]], $this->rendered(RdrOpenForm::class));
    }

    public function test_a_table_repeater_behaves_the_same(): void
    {
        $rows             = $this->rendered(RdrTableForm::class);
        $rows[0]['price'] = 1;
        $rows[]           = ['name' => 'c', 'price' => 999];

        $this->assertSame([['name' => 'a', 'price' => 10], ['name' => 'b', 'price' => 20], ['name' => 'c', 'price' => null]], $this->submit(RdrTableForm::class, $rows));
    }

    public function test_a_form_filled_from_data_restores_from_that_data(): void
    {
        $stored = ['items' => [['name' => 'a', 'price' => 10]]];
        $rows   = RdrForm::make()->fill($stored)->toArray()['data']['items'];

        $rows[0]['price'] = 1;

        $this->assertSame([['name' => 'a', 'price' => 10]], RdrForm::make()->fill($stored)->getState(['items' => $rows])['items']);
    }

    public function test_a_nested_repeater_stays_enforced_in_the_browser_only(): void
    {
        $this->order->update(['items' => [['name' => 'a', 'lines' => [['qty' => 1]]]]]);

        $rows = $this->rendered(RdrNestedForm::class);
        $this->assertArrayNotHasKey(Repeater::ROW_KEY, $rows[0]);

        $rows[0]['lines'][0]['qty'] = 50;

        $this->assertSame(50, $this->submit(RdrNestedForm::class, $rows)[0]['lines'][0]['qty']);
    }

    public function test_a_hook_that_reorders_rows_keeps_their_stored_values(): void
    {
        $rows = $this->afterHook('reverse');

        $this->assertSame([['name' => 'b', 'price' => 20], ['name' => 'a', 'price' => 10]], $this->submit(RdrHookForm::class, $rows));
    }

    public function test_a_hook_that_drops_a_row_keeps_the_others_values(): void
    {
        $rows = $this->afterHook('drop');

        $this->assertSame([['name' => 'b', 'price' => 20]], $this->submit(RdrHookForm::class, $rows));
    }

    public function test_a_row_a_hook_adds_is_new_even_with_a_stored_rows_values(): void
    {
        $rows = $this->afterHook('add');

        $this->assertArrayNotHasKey(Repeater::ROW_KEY, $rows[0]);
        $rows[0]['price'] = 999;

        $this->assertSame(
            [['name' => 'a', 'price' => null], ['name' => 'a', 'price' => 10], ['name' => 'b', 'price' => 20]],
            $this->submit(RdrHookForm::class, $rows),
        );
    }

    /**
     * The rows a live hook sets, as the recompute endpoint returns them.
     *
     * @return list<array<string, mixed>>
     */
    private function afterHook(string $op): array
    {
        $data   = $this->rendered(RdrHookForm::class);
        $result = RdrHookForm::make($this->order)->fill($this->order)->recompute(['items' => $data, 'op' => $op], ['op']);

        return $result['changes']['items'];
    }

    /**
     * The rows as the edit form renders them.
     *
     * @param  class-string<Form>         $form
     * @return list<array<string, mixed>>
     */
    private function rendered(string $form): array
    {
        return $form::make($this->order)->fill($this->order)->toArray()['data']['items'];
    }

    /**
     * The saved rows for a submit of `$rows` to the edit form.
     *
     * @param  class-string<Form>         $form
     * @param  array<int, mixed>          $rows
     * @return list<array<string, mixed>>
     */
    private function submit(string $form, array $rows): array
    {
        return $form::make($this->order->fresh())->getState(['items' => $rows])['items'];
    }
}
