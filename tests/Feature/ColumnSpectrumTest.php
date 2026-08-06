<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Columns\ColorColumn;
use Happones\Kinetix\Tables\Columns\IconColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CstPost extends Model
{
    protected $table = 'cst_posts';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['tags' => 'array'];
}

class ColumnSpectrumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cst_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('tags')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->boolean('active')->default(true);
        });
    }

    private function record(array $attributes = []): CstPost
    {
        return CstPost::create(array_merge([
            'title' => 'Hello',
            'body'  => '<p>Rich <strong>text</strong> body</p>',
            'tags'  => ['php', 'vue'],
            'score' => 1234.5,
        ], $attributes));
    }

    public function test_array_state_implodes_with_the_separator_for_plain_text(): void
    {
        $record = $this->record();

        $this->assertSame(
            'php, vue',
            TextColumn::make('tags')->separator()->getState($record),
        );
        $this->assertSame(
            'php | vue',
            TextColumn::make('tags')->separator(' | ')->getState($record),
        );
        // Even without separator(), an array never leaks raw to the client.
        $this->assertSame(
            'php, vue',
            TextColumn::make('tags')->getState($record),
        );
    }

    public function test_array_state_stays_an_array_for_badge_columns_one_pill_per_item(): void
    {
        $record = $this->record();

        $this->assertSame(
            ['php', 'vue'],
            TextColumn::make('tags')->badge()->getState($record),
        );
    }

    public function test_html_with_limit_strips_tags_before_truncating(): void
    {
        $record = $this->record();

        // Never a mid-tag cut: tags are stripped first.
        $this->assertSame(
            'Rich text bo...',
            TextColumn::make('body')->html()->limit(12)->getState($record),
        );

        // Without a limit the markup passes through untouched (trusted).
        $this->assertSame(
            '<p>Rich <strong>text</strong> body</p>',
            TextColumn::make('body')->html()->getState($record),
        );
    }

    public function test_numeric_formats_with_decimals(): void
    {
        $record = $this->record();

        $this->assertSame(
            '1,234.50',
            TextColumn::make('score')->numeric(2, 'en')->getState($record),
        );
        // NumberFormatter rounds half-to-even (1234.5 → 1,234).
        $this->assertSame(
            '1,234',
            TextColumn::make('score')->numeric(0, 'en')->getState($record),
        );
    }

    public function test_url_rides_per_record_and_serializes_into_the_row(): void
    {
        $this->record();

        $table = Table::make(CstPost::query())->columns([
            TextColumn::make('title')->url(fn (CstPost $post) => "/posts/{$post->id}", openUrlInNewTab: true),
        ]);

        $data = $table->toData();

        $this->assertSame('/posts/1', $data->records[0]->urls['title']);
        $this->assertTrue($data->columns[0]->openUrlInNewTab);
    }

    public function test_tooltip_wrap_and_html_serialize_on_the_column(): void
    {
        $this->record();

        $table = Table::make(CstPost::query())->columns([
            TextColumn::make('title')->tooltip('The post title')->wrap(),
            TextColumn::make('body')->html(),
        ]);

        $data = $table->toData();

        $this->assertSame('The post title', $data->columns[0]->tooltip);
        $this->assertTrue($data->columns[0]->wrap);
        $this->assertTrue($data->columns[1]->isHtml);
    }

    public function test_icon_column_custom_boolean_icons_and_colors(): void
    {
        $on  = $this->record(['active' => true]);
        $off = $this->record(['active' => false]);

        $column = IconColumn::make('active')
            ->boolean()
            ->trueIcon('shield-check')->falseIcon('shield-off')
            ->trueColor('info')->falseColor('gray')
            ->size(28);

        $this->assertSame('shield-check', $column->getIcon($on));
        $this->assertSame('shield-off', $column->getIcon($off));
        $this->assertSame('info', $column->getIconColor($on));
        $this->assertSame('gray', $column->getIconColor($off));
        $this->assertSame(28, $column->toData()->size);
    }

    public function test_color_column_copyable_still_serializes_through_the_base(): void
    {
        $this->assertTrue(ColorColumn::make('color')->copyable()->toData()->isCopyable);
        $this->assertNull(ColorColumn::make('color')->toData()->isCopyable);
    }
}
