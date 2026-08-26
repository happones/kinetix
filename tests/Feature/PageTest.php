<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Pages\Page;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class PageTestRecord extends Model
{
    protected $table = 'posts';

    public $incrementing = true;

    protected $guarded = [];
}

class DeclaredPage extends Page
{
    protected ?string $heading = 'Declared heading';

    protected bool $stickyFooter = true;

    protected function buildHeaderActions(): array
    {
        return [Action::make('export')->label('Export')];
    }

    protected function buildFooterActions(): array
    {
        return [Action::make('save')->label('Save')];
    }
}

/**
 * The page builder declares CHROME only — heading, description and the two
 * action bars — so the body stays whatever the Vue page renders.
 */
class PageTest extends TestCase
{
    public function test_it_serializes_its_heading_description_and_both_bars(): void
    {
        $data = Page::make('Inventory')
            ->description('Everything you stock.')
            ->headerActions([Action::make('import')->label('Import')])
            ->footerActions([
                Action::make('cancel')->label('Cancel'),
                Action::make('save')->label('Save'),
            ])
            ->toArray();

        $this->assertSame('Inventory', $data['heading']);
        $this->assertSame('Everything you stock.', $data['description']);
        $this->assertCount(1, $data['headerActions']);
        $this->assertCount(2, $data['footerActions']);
        $this->assertSame('Import', $data['headerActions'][0]['label']);
        // Order is preserved: the footer renders primary-last on purpose.
        $this->assertSame(['Cancel', 'Save'], array_column($data['footerActions'], 'label'));
    }

    public function test_it_defaults_to_no_chrome_at_all(): void
    {
        $data = Page::make()->toArray();

        $this->assertNull($data['heading']);
        $this->assertNull($data['description']);
        $this->assertSame([], $data['headerActions']);
        $this->assertSame([], $data['footerActions']);
        $this->assertFalse($data['stickyFooter']);
    }

    public function test_a_subclass_declares_its_own_chrome(): void
    {
        $data = DeclaredPage::make()->toArray();

        $this->assertSame('Declared heading', $data['heading']);
        $this->assertTrue($data['stickyFooter']);
        $this->assertSame('Export', $data['headerActions'][0]['label']);
        $this->assertSame('Save', $data['footerActions'][0]['label']);
    }

    public function test_a_constructor_heading_does_not_override_a_subclass_one(): void
    {
        // `Page::make('X')` on a class that already declares a heading must not
        // silently replace it — the class is the more specific declaration.
        $this->assertSame('Declared heading', DeclaredPage::make('Ignored')->getHeading());
        // …but the fluent setter is explicit, so it wins.
        $this->assertSame('Chosen', DeclaredPage::make()->heading('Chosen')->getHeading());
    }

    public function test_an_unauthorized_or_hidden_action_never_reaches_the_page(): void
    {
        $data = Page::make('Inventory')
            ->headerActions([
                Action::make('visible')->label('Visible'),
                Action::make('denied')->label('Denied')->authorize(fn () => false),
                Action::make('hidden')->label('Hidden')->hidden(),
            ])
            ->footerActions([
                Action::make('nope')->label('Nope')->authorize(fn () => false),
            ])
            ->toArray();

        $this->assertSame(['Visible'], array_column($data['headerActions'], 'label'));
        $this->assertSame([], $data['footerActions']);
    }

    public function test_the_record_is_passed_to_action_url_closures(): void
    {
        $record = new PageTestRecord(['id' => 7]);
        $record->setAttribute('id', 7);

        $data = Page::make('Item')
            ->record($record)
            ->headerActions([
                Action::make('show')->label('Show')
                    ->url(fn (Model $r): string => "/items/{$r->getKey()}"),
            ])
            ->toArray();

        $this->assertSame('/items/7', $data['headerActions'][0]['url']);
    }

    public function test_it_is_json_serializable_for_an_inertia_prop(): void
    {
        $page = Page::make('Inventory')->stickyFooter();

        $decoded = json_decode((string) json_encode($page), true);

        $this->assertSame('Inventory', $decoded['heading']);
        $this->assertTrue($decoded['stickyFooter']);
    }
}
