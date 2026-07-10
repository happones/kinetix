<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Widgets\Widget;
use Happones\Kinetix\Widgets\WidgetsGrid;
use Illuminate\Support\Facades\Gate;

/** A minimal concrete widget — counts getData() calls to prove skipped widgets never compute their payload. */
class ProbeWidget extends Widget
{
    protected string $type = 'probe';

    public int $dataCalls = 0;

    protected function getData(): array
    {
        $this->dataCalls++;

        return ['probed' => true];
    }
}

class WidgetAuthorizationTest extends TestCase
{
    public function test_widgets_are_visible_by_default(): void
    {
        $this->assertTrue(ProbeWidget::make()->shouldRender());
    }

    public function test_visible_false_hides_the_widget(): void
    {
        $this->assertFalse(ProbeWidget::make()->visible(false)->shouldRender());
    }

    public function test_hidden_true_hides_the_widget(): void
    {
        $this->assertFalse(ProbeWidget::make()->hidden(true)->shouldRender());
    }

    public function test_visible_and_hidden_accept_closures(): void
    {
        $this->assertFalse(ProbeWidget::make()->visible(fn () => false)->shouldRender());
        $this->assertTrue(ProbeWidget::make()->visible(fn () => true)->shouldRender());
        $this->assertFalse(ProbeWidget::make()->hidden(fn () => true)->shouldRender());
    }

    public function test_hidden_takes_precedence_over_visible(): void
    {
        $widget = ProbeWidget::make()->visible(true)->hidden(true);

        $this->assertFalse($widget->shouldRender());
    }

    public function test_authorize_accepts_a_bare_gate_ability(): void
    {
        Gate::define('viewFinancials', fn ($user = null): bool => false);
        $this->assertFalse(ProbeWidget::make()->authorize('viewFinancials')->shouldRender());

        Gate::define('viewFinancials', fn ($user = null): bool => true);
        $this->assertTrue(ProbeWidget::make()->authorize('viewFinancials')->shouldRender());
    }

    public function test_authorize_accepts_an_ability_with_a_subject(): void
    {
        // Gate refuses to invoke a callback for a guest (no actingAs() here)
        // unless the first param allows null — match the other cases below.
        Gate::define('viewTeamStats', fn ($user = null, string $team = ''): bool => $team === 'acme');

        $this->assertTrue(ProbeWidget::make()->authorize('viewTeamStats', 'acme')->shouldRender());
        $this->assertFalse(ProbeWidget::make()->authorize('viewTeamStats', 'other')->shouldRender());
    }

    public function test_authorize_accepts_a_closure(): void
    {
        $this->assertFalse(ProbeWidget::make()->authorize(fn (): bool => false)->shouldRender());
        $this->assertTrue(ProbeWidget::make()->authorize(fn (): bool => true)->shouldRender());
    }

    public function test_authorize_accepts_a_bare_boolean(): void
    {
        $this->assertFalse(ProbeWidget::make()->authorize(false)->shouldRender());
        $this->assertTrue(ProbeWidget::make()->authorize(true)->shouldRender());
    }

    public function test_visible_and_authorize_both_must_pass(): void
    {
        Gate::define('viewFinancials', fn ($user = null): bool => true);

        $widget = ProbeWidget::make()->visible(false)->authorize('viewFinancials');

        $this->assertFalse($widget->shouldRender());
    }

    public function test_the_grid_omits_hidden_widgets_and_never_computes_their_data(): void
    {
        Gate::define('viewFinancials', fn ($user = null): bool => false);

        $visible  = ProbeWidget::make()->id('visible')->title('Visible')->sort(2);
        $denied   = ProbeWidget::make()->id('denied')->title('Denied')->authorize('viewFinancials')->sort(1);
        $unlisted = ProbeWidget::make()->id('unlisted')->title('Unlisted')->visible(false)->sort(0);

        $grid = WidgetsGrid::make()->widgets([$visible, $denied, $unlisted])->toArray();

        $this->assertCount(1, $grid['widgets']);
        $this->assertSame('visible', $grid['widgets'][0]['id']);

        // The excluded widgets' (potentially costly) getData() never ran.
        $this->assertSame(1, $visible->dataCalls);
        $this->assertSame(0, $denied->dataCalls);
        $this->assertSame(0, $unlisted->dataCalls);
    }

    public function test_the_grid_still_sorts_remaining_widgets_after_filtering(): void
    {
        $a = ProbeWidget::make()->id('a')->sort(3);
        $b = ProbeWidget::make()->id('b')->sort(1)->visible(false);
        $c = ProbeWidget::make()->id('c')->sort(2);

        $grid = WidgetsGrid::make()->widgets([$a, $b, $c])->toArray();

        $this->assertSame(['c', 'a'], array_column($grid['widgets'], 'id'));
    }
}
