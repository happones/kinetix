<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\Contracts\HasLabel;
use Happones\Kinetix\Tables\Columns\SelectColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;

enum SampleStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Published = 'published';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}

class SelectOptionsTest extends TestCase
{
    public function test_select_column_resolves_enum_class_options(): void
    {
        $options = SelectColumn::make('status')->options(SampleStatus::class)->getOptions();

        $this->assertSame(['draft' => 'Draft', 'published' => 'Published'], $options);
    }

    public function test_select_column_keeps_array_options(): void
    {
        $options = SelectColumn::make('status')->options(['a' => 'A', 'b' => 'B'])->getOptions();

        $this->assertSame(['a' => 'A', 'b' => 'B'], $options);
    }

    public function test_select_column_non_enum_string_falls_back_to_empty(): void
    {
        // A string that is not a UnitEnum subclass must not be returned as the options array.
        $options = SelectColumn::make('status')->options('Not\\An\\Enum')->getOptions();

        $this->assertSame([], $options);
    }

    public function test_select_filter_resolves_enum_class_options(): void
    {
        $options = SelectFilter::make('status')->options(SampleStatus::class)->getOptions();

        $this->assertSame(['draft' => 'Draft', 'published' => 'Published'], $options);
    }
}
