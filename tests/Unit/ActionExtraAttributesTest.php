<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tests\TestCase;

/**
 * Every rendered action carries `data-action="<name>"` (the frontend adds
 * it); extraAttributes() adds the rest — minus anything that would run
 * script or take the stable hook away.
 */
class ActionExtraAttributesTest extends TestCase
{
    public function test_extra_attributes_are_serialized_and_merged_across_calls(): void
    {
        $data = Action::make('create')
            ->extraAttributes(['data-tour' => 'create', 'data-count' => 3])
            ->extraAttributes(['aria-keyshortcuts' => 'c', 'data-beta' => true])
            ->toData();

        $this->assertSame([
            'data-tour'         => 'create',
            'data-count'        => '3',
            'aria-keyshortcuts' => 'c',
            'data-beta'         => '',
        ], $data?->extraAttributes);
    }

    public function test_event_handlers_invalid_names_and_the_action_hook_are_dropped(): void
    {
        $data = Action::make('create')->extraAttributes([
            'onclick'     => 'alert(1)',
            'OnMouseOver' => 'alert(1)',
            'data-action' => 'something-else',
            'bad name'    => 'x',
            '"><script>'  => 'x',
            'data-off'    => false,
            'data-null'   => null,
            'data-list'   => ['a'],
            'title'       => 'Kept',
        ])->toData();

        $this->assertSame(['title' => 'Kept'], $data?->extraAttributes);
    }

    public function test_no_extra_attributes_serialize_as_null(): void
    {
        $this->assertNull(Action::make('create')->toData()?->extraAttributes);
    }
}
