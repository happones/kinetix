<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Tests\TestCase;

class CheckboxListTest extends TestCase
{
    public function test_select_all_is_opt_in_and_reaches_the_field_data(): void
    {
        $plain = CheckboxList::make('channels')->options(['mail' => 'Mail'])->toData('create');
        $bulk  = CheckboxList::make('channels')->options(['mail' => 'Mail'])->bulkToggleable()->inline()->toData('create');

        $this->assertFalse($plain?->isBulkToggleable);
        $this->assertTrue($bulk?->isBulkToggleable);
        $this->assertTrue($bulk->isInline);
        $this->assertFalse(CheckboxList::make('x')->bulkToggleable()->bulkToggleable(false)->toData('create')?->isBulkToggleable);
    }
}
