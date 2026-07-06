<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Infolists\Components\IconEntry;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Tables\Columns\IconColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

// A custom class simulating an Enum or custom object with getLabel, getColor, and getIcon.
// Crucially, it does NOT implement Happones\Kinetix\Support\Contracts\HasLabel, HasColor, or HasIcon.
class CustomMockEnum
{
    public function getLabel(): string
    {
        return 'Custom Label';
    }

    public function getColor(): string
    {
        return 'custom-green';
    }

    public function getIcon(): string
    {
        return 'custom-check';
    }
}

// A mock UnitEnum that has getLabel, getColor, getIcon
enum CustomMockUnitEnum
{
    case Active;

    public function getLabel(): string
    {
        return 'Active Case';
    }

    public function getColor(): string
    {
        return 'active-green';
    }

    public function getIcon(): string
    {
        return 'active-check';
    }
}

class CompatTestModel extends Model
{
    protected $guarded = [];
}

class CustomContractCompatTest extends TestCase
{
    public function test_select_filter_resolves_label_from_custom_unit_enum(): void
    {
        $filter  = SelectFilter::make('status')->options(CustomMockUnitEnum::class);
        $options = $filter->getOptions();

        $this->assertSame([
            'Active' => 'Active Case',
        ], $options);
    }

    public function test_text_column_resolves_label_and_color_from_custom_object(): void
    {
        $record = new CompatTestModel(['status' => new CustomMockEnum]);

        $column        = TextColumn::make('status');
        $resolvedValue = $column->getState($record);
        $resolvedColor = $column->getBadgeColor($record);

        $this->assertSame('Custom Label', $resolvedValue);
        $this->assertSame('custom-green', $resolvedColor);
    }

    public function test_icon_column_resolves_icon_and_color_from_custom_object(): void
    {
        $record = new CompatTestModel(['status' => new CustomMockEnum]);

        $column        = IconColumn::make('status');
        $resolvedIcon  = $column->getIcon($record);
        $resolvedColor = $column->getIconColor($record);

        $this->assertSame('custom-check', $resolvedIcon);
        $this->assertSame('custom-green', $resolvedColor);
    }

    public function test_infolist_entry_resolves_label_color_and_icon_from_custom_object(): void
    {
        $record = new CompatTestModel(['status' => new CustomMockEnum]);

        $entry         = TextEntry::make('status');
        $resolvedValue = $entry->getState($record);
        $resolvedColor = $entry->getColor($record);
        $resolvedIcon  = $entry->getIcon($record);

        $this->assertSame('Custom Label', $resolvedValue);
        $this->assertSame('custom-green', $resolvedColor);
        $this->assertSame('custom-check', $resolvedIcon);
    }

    public function test_infolist_icon_entry_resolves_icon_and_color_from_custom_object(): void
    {
        $record = new CompatTestModel(['status' => new CustomMockEnum]);

        $entry         = IconEntry::make('status');
        $resolvedIcon  = $entry->getIcon($record);
        $resolvedColor = $entry->getColor($record);

        $this->assertSame('custom-check', $resolvedIcon);
        $this->assertSame('custom-green', $resolvedColor);
    }
}
