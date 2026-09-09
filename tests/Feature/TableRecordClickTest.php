<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ViewAction;
use Happones\Kinetix\Data\TableRowData;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class TrcPost extends Model
{
    protected $table = 'trc_posts';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A row's click target is inferred from its own `view` then `edit` actions —
 * a routed action becomes the row URL, a modal action becomes the row action —
 * and every switch that turns it off (config, per table, per channel) holds.
 */
class TableRecordClickTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('trc_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->boolean('is_public')->default(true);
        });

        TrcPost::create(['title' => 'Hello']);

        // ViewAction / EditAction check the model policy per record; grant every
        // ability so the tests exercise the click inference, not authorization.
        Gate::before(fn (?Authenticatable $user): bool => true);
    }

    /**
     * @param array<int, Action> $recordActions
     */
    protected function firstRow(array $recordActions, ?callable $configure = null): TableRowData
    {
        $table = Table::make(TrcPost::query())
            ->columns([TextColumn::make('title')])
            ->recordActions($recordActions);

        if ($configure !== null) {
            $configure($table);
        }

        return $table->toData()->records[0];
    }

    public function test_a_routed_view_action_becomes_the_row_url(): void
    {
        $row = $this->firstRow([
            ActionGroup::make([
                ViewAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}"),
                EditAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}/edit"),
            ]),
        ]);

        $this->assertSame('/posts/1', $row->recordUrl);
        $this->assertFalse($row->recordUrlInNewTab);
        $this->assertNull($row->recordAction);
    }

    public function test_a_modal_view_action_becomes_the_row_action(): void
    {
        $row = $this->firstRow([
            ActionGroup::make([
                ViewAction::make()->modal('view'),
                EditAction::make()->modal('edit'),
            ]),
        ]);

        $this->assertNull($row->recordUrl);
        $this->assertSame('view', $row->recordAction);
    }

    public function test_edit_is_the_fallback_when_there_is_no_view_action(): void
    {
        $row = $this->firstRow([
            EditAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}/edit"),
        ]);

        $this->assertSame('/posts/1/edit', $row->recordUrl);
    }

    public function test_an_unauthorized_view_action_falls_through_to_edit(): void
    {
        $row = $this->firstRow([
            ViewAction::make()->authorize(fn (): bool => false)->modal('view'),
            EditAction::make()->authorize(fn (): bool => true)->modal('edit'),
        ]);

        $this->assertSame('edit', $row->recordAction);
    }

    public function test_a_view_action_that_needs_confirmation_is_a_row_action_not_a_link(): void
    {
        $row = $this->firstRow([
            ViewAction::make()
                ->url(fn (TrcPost $post) => "/posts/{$post->id}")
                ->requiresConfirmation(),
        ]);

        $this->assertNull($row->recordUrl);
        $this->assertSame('view', $row->recordAction);
    }

    public function test_a_row_without_view_or_edit_actions_is_inert(): void
    {
        $row = $this->firstRow([
            Action::make('archive')->label('Archive')->dispatch('archive'),
        ]);

        $this->assertNull($row->recordUrl);
        $this->assertNull($row->recordAction);
    }

    public function test_an_explicit_record_url_wins_over_the_inferred_action(): void
    {
        $row = $this->firstRow(
            [ViewAction::make()->modal('view')],
            fn (Table $table) => $table->recordUrl(fn (TrcPost $post) => "/custom/{$post->id}", shouldOpenInNewTab: true),
        );

        $this->assertSame('/custom/1', $row->recordUrl);
        $this->assertTrue($row->recordUrlInNewTab);
        $this->assertNull($row->recordAction);
    }

    public function test_record_url_null_switches_the_url_channel_off_only(): void
    {
        $row = $this->firstRow(
            [
                ViewAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}"),
                EditAction::make()->modal('edit'),
            ],
            fn (Table $table) => $table->recordUrl(null),
        );

        $this->assertNull($row->recordUrl);
        // `view` is a link, so with the URL channel off nothing is inferred.
        $this->assertNull($row->recordAction);
    }

    public function test_an_explicit_record_action_names_any_rendered_row_action(): void
    {
        $row = $this->firstRow(
            [
                ViewAction::make()->modal('view'),
                Action::make('archive')->label('Archive')->dispatch('archive'),
            ],
            fn (Table $table) => $table->recordAction('archive'),
        );

        $this->assertSame('archive', $row->recordAction);
    }

    public function test_an_explicit_record_action_the_row_does_not_render_is_dropped(): void
    {
        $row = $this->firstRow(
            [Action::make('archive')->label('Archive')->authorize(fn (): bool => false)->dispatch('archive')],
            fn (Table $table) => $table->recordAction('archive'),
        );

        $this->assertNull($row->recordAction);
    }

    public function test_a_record_action_closure_resolves_per_record(): void
    {
        $row = $this->firstRow(
            [ViewAction::make()->modal('view'), EditAction::make()->modal('edit')],
            fn (Table $table) => $table->recordAction(fn (TrcPost $post): ?string => $post->is_public ? 'edit' : null),
        );

        $this->assertSame('edit', $row->recordAction);
    }

    public function test_record_action_null_switches_the_action_channel_off(): void
    {
        $row = $this->firstRow(
            [ViewAction::make()->modal('view')],
            fn (Table $table) => $table->recordAction(null),
        );

        $this->assertNull($row->recordAction);
        $this->assertNull($row->recordUrl);
    }

    public function test_clickable_rows_false_makes_the_table_inert(): void
    {
        $row = $this->firstRow(
            [ViewAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}")],
            fn (Table $table) => $table
                ->recordUrl(fn (TrcPost $post) => "/custom/{$post->id}")
                ->clickableRows(false),
        );

        $this->assertNull($row->recordUrl);
        $this->assertNull($row->recordAction);
    }

    public function test_the_config_switch_disables_rows_everywhere_unless_a_table_opts_back_in(): void
    {
        config()->set('kinetix.tables.clickable_rows', false);

        $actions = fn (): array => [ViewAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}")];

        $this->assertNull($this->firstRow($actions())->recordUrl);

        $optedIn = $this->firstRow($actions(), fn (Table $table) => $table->clickableRows());

        $this->assertSame('/posts/1', $optedIn->recordUrl);
    }

    public function test_open_record_url_in_new_tab_applies_to_the_inferred_url(): void
    {
        $row = $this->firstRow(
            [ViewAction::make()->url(fn (TrcPost $post) => "/posts/{$post->id}")],
            fn (Table $table) => $table->openRecordUrlInNewTab(),
        );

        $this->assertSame('/posts/1', $row->recordUrl);
        $this->assertTrue($row->recordUrlInNewTab);
    }
}
