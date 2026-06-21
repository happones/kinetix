<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Infolists\Components\Fieldset;
use Happones\Kinetix\Infolists\Components\Tab;
use Happones\Kinetix\Infolists\Components\Tabs;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tests\TestCase;

class InfolistTest extends TestCase
{
    public function test_null_record_is_safe(): void
    {
        $data = Infolist::make(null)
            ->schema([TextEntry::make('name')->placeholder('N/A')])
            ->toArray();

        $this->assertSame('N/A', $data['schema'][0]['placeholder']);
        $this->assertNull($data['schema'][0]['state']);
    }

    public function test_badge_color_closure_resolves(): void
    {
        $entry = TextEntry::make('status')
            ->badge()
            ->color(fn ($state) => $state === 'open' ? 'success' : 'gray')
            ->state(fn () => 'open');

        $data = $entry->toData('view', null);

        $this->assertSame('open', $data->state);
        $this->assertSame('success', $data->color);
        $this->assertTrue($data->isBadge);
    }

    public function test_fieldset_layout_serializes(): void
    {
        $data = Fieldset::make('Billing')
            ->columns(6)
            ->schema([TextEntry::make('plan')])
            ->toData('view', null);

        $this->assertSame('fieldset', $data->type);
        $this->assertSame('Billing', $data->heading);
        $this->assertSame(6, $data->columns);
        $this->assertCount(1, $data->schema);
        $this->assertSame('plan', $data->schema[0]->name);
    }

    public function test_tabs_layout_serializes_nested_tabs(): void
    {
        $data = Tabs::make()
            ->tabs([
                Tab::make('Profile')->icon('user')->schema([TextEntry::make('name')]),
                Tab::make('Account')->schema([TextEntry::make('email')]),
            ])
            ->toData('view', null);

        $this->assertSame('tabs', $data->type);
        $this->assertCount(2, $data->schema);
        $this->assertSame('tab', $data->schema[0]->type);
        $this->assertSame('Profile', $data->schema[0]->heading);
        $this->assertSame('user', $data->schema[0]->icon);
        $this->assertSame('name', $data->schema[0]->schema[0]->name);
        $this->assertSame('Account', $data->schema[1]->heading);
    }

    public function test_hidden_tab_is_stripped(): void
    {
        $data = Tabs::make()
            ->tabs([
                Tab::make('Visible')->schema([TextEntry::make('a')]),
                Tab::make('Hidden')->hidden()->schema([TextEntry::make('b')]),
            ])
            ->toData('view', null);

        $this->assertCount(1, $data->schema);
        $this->assertSame('Visible', $data->schema[0]->heading);
    }
}
