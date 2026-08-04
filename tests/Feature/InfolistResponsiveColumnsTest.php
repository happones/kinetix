<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Infolists\Components\Section;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tests\TestCase;

class InfolistResponsiveColumnsTest extends TestCase
{
    public function test_columns_accept_breakpoint_maps_through_to_the_payload(): void
    {
        $payload = Infolist::make()
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                Section::make('Order')
                    ->columns(['sm' => 2, 'xl' => 3])
                    ->schema([
                        TextEntry::make('customer')->columnSpan(['sm' => 2]),
                    ]),
            ])
            ->toData()
            ->toArray();

        $this->assertSame(['default' => 1, 'sm' => 2], $payload['columns']);
        $this->assertSame(['sm' => 2, 'xl' => 3], $payload['schema'][0]['columns']);
        $this->assertSame(['sm' => 2], $payload['schema'][0]['schema'][0]['columnSpan']);
    }

    public function test_int_columns_keep_serializing_as_ints(): void
    {
        $payload = Infolist::make()
            ->columns(2)
            ->schema([TextEntry::make('customer')])
            ->toData()
            ->toArray();

        $this->assertSame(2, $payload['columns']);
    }
}
