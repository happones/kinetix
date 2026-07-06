<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\ViewColumn;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class ViewColumnTest extends TestCase
{
    public function test_view_column_type(): void
    {
        $column = ViewColumn::make('avatar')
            ->view('CustomAvatarComponent');

        $this->assertEquals('view', $column->toArray()['type']);
        $this->assertEquals('CustomAvatarComponent', $column->toArray()['view']);
    }

    public function test_view_column_evaluates_props_dynamically(): void
    {
        $column = ViewColumn::make('status')
            ->view('UserStatus')
            ->props(fn ($record) => [
                'isOnline' => $record->active,
                'role'     => 'admin',
            ]);

        $record = new class extends Model
        {
            protected $attributes = ['active' => true];
        };

        $props = $column->getViewProps($record);
        $this->assertEquals([
            'isOnline' => true,
            'role'     => 'admin',
        ], $props);
    }
}
