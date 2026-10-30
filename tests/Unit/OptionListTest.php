<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\Radio;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Support\OptionList;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;

/**
 * Options keep their order on the way to the browser, where an object would
 * list integer-like keys first, ascending.
 */
class OptionListTest extends TestCase
{
    public function test_ids_ordered_by_name_travel_as_a_list(): void
    {
        $this->assertSame([
            ['value' => 12, 'label' => 'Aguascalientes'],
            ['value' => 3, 'label' => 'Baja California'],
            ['value' => 7, 'label' => 'Colima'],
        ], OptionList::forWire([12 => 'Aguascalientes', 3 => 'Baja California', 7 => 'Colima']));
    }

    public function test_a_map_javascript_keeps_in_order_is_unchanged(): void
    {
        $this->assertSame(['b' => 'B', 'a' => 'A'], OptionList::forWire(['b' => 'B', 'a' => 'A']));
        $this->assertSame([1 => 'One', 2 => 'Two', 'x' => 'X'], OptionList::forWire([1 => 'One', 2 => 'Two', 'x' => 'X']));
        $this->assertSame(['007' => 'Bond', '-1' => 'None'], OptionList::forWire(['007' => 'Bond', '-1' => 'None']));
        $this->assertSame(['A', 'B'], OptionList::forWire(['A', 'B']));
        $this->assertNull(OptionList::forWire(null));
    }

    public function test_a_string_key_before_an_id_is_kept_first(): void
    {
        $this->assertSame([
            ['value' => '', 'label' => 'None'],
            ['value' => 5, 'label' => 'Five'],
        ], OptionList::forWire(['' => 'None', 5 => 'Five']));
    }

    public function test_an_option_list_passes_through(): void
    {
        $list = [['value' => 2, 'label' => 'B'], ['value' => 1, 'label' => 'A']];

        $this->assertSame($list, OptionList::forWire($list));
    }

    public function test_fields_and_filters_send_ordered_options(): void
    {
        $ordered = [9 => 'Ana', 2 => 'Beto'];
        $list    = [['value' => 9, 'label' => 'Ana'], ['value' => 2, 'label' => 'Beto']];

        $this->assertSame($list, Select::make('user_id')->options($ordered)->toData('create')?->options);
        $this->assertSame($list, Radio::make('user_id')->options($ordered)->toData('create')?->options);
        $this->assertSame($list, CheckboxList::make('user_ids')->options($ordered)->toData('create')?->options);
        $this->assertSame($list, SelectFilter::make('user_id')->options($ordered)->toData()->options);
        $this->assertSame(['a' => 'A'], Select::make('kind')->options(['a' => 'A'])->toData('create')?->options);
    }
}
