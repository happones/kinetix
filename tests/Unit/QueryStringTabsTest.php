<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\Step;
use Happones\Kinetix\Forms\Components\Tab;
use Happones\Kinetix\Forms\Components\Tabs;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Wizard;
use Happones\Kinetix\Infolists\Components\Tab as InfolistTab;
use Happones\Kinetix\Infolists\Components\Tabs as InfolistTabs;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Tests\TestCase;

/**
 * Tabs and wizard steps keep the open tab/step in the query string by default:
 * each group names its param and each child its key, so a reload or a shared
 * link reopens it. A config switch and a per-group `false` turn it off.
 */
class QueryStringTabsTest extends TestCase
{
    public function test_form_tabs_persist_in_the_tab_param_by_default_keyed_by_label_slug(): void
    {
        $data = Tabs::make()->tabs([
            Tab::make('Datos generales')->schema([TextInput::make('name')]),
            Tab::make('Seguridad')->schema([TextInput::make('password')]),
        ])->toData('edit', null);

        $this->assertSame('tab', $data->queryParam);
        $this->assertSame('datos-generales', $data->schema[0]->urlKey);
        $this->assertSame('seguridad', $data->schema[1]->urlKey);
    }

    public function test_an_explicit_key_wins_and_duplicates_stay_unique(): void
    {
        $data = Tabs::make()->tabs([
            Tab::make(__('Security'))->key('security')->schema([TextInput::make('a')]),
            Tab::make('Notes')->schema([TextInput::make('b')]),
            Tab::make('Notes')->schema([TextInput::make('c')]),
            Tab::make('???')->schema([TextInput::make('d')]),
        ])->toData('edit', null);

        $this->assertSame(
            ['security', 'notes', 'notes-2', 'tab-4'],
            array_map(fn ($tab) => $tab->urlKey, $data->schema),
        );
    }

    public function test_a_group_can_use_its_own_param_or_opt_out(): void
    {
        $named = Tabs::make()->persistInQueryString('section')->tabs([
            Tab::make('One')->schema([TextInput::make('a')]),
        ])->toData('edit', null);

        $off = Tabs::make()->persistInQueryString(false)->tabs([
            Tab::make('One')->schema([TextInput::make('a')]),
        ])->toData('edit', null);

        $this->assertSame('section', $named->queryParam);
        $this->assertNull($off->queryParam);
        // Still keyed: tours point at tabs by key whether or not the URL does.
        $this->assertSame('one', $off->schema[0]->urlKey);
    }

    public function test_hidden_tabs_take_no_key(): void
    {
        $data = Tabs::make()->tabs([
            Tab::make('Hidden')->hidden()->schema([TextInput::make('a')]),
            Tab::make('Visible')->schema([TextInput::make('b')]),
        ])->toData('edit', null);

        $this->assertCount(1, $data->schema);
        $this->assertSame('visible', $data->schema[0]->urlKey);
    }

    public function test_the_config_switch_turns_tabs_and_steps_off(): void
    {
        config()->set('kinetix.forms.query_string.tabs', false);
        config()->set('kinetix.forms.query_string.steps', false);

        $tabs   = Tabs::make()->tabs([Tab::make('One')->schema([TextInput::make('a')])])->toData('edit', null);
        $info   = InfolistTabs::make()->tabs([InfolistTab::make('One')->schema([TextEntry::make('a')])])->toData('view', null);
        $wizard = Wizard::make()->steps([Step::make('One')->schema([TextInput::make('a')])])->toData('create', null);

        $this->assertNull($tabs->queryParam);
        $this->assertNull($info->queryParam);
        $this->assertNull($wizard->queryParam);

        // An explicit opt-in still applies with the switch off.
        $optedIn = Tabs::make()->persistInQueryString()->tabs([
            Tab::make('One')->schema([TextInput::make('a')]),
        ])->toData('edit', null);
        $this->assertSame('tab', $optedIn->queryParam);
    }

    public function test_a_published_config_without_the_switch_keeps_it_on(): void
    {
        config()->set('kinetix.forms', ['rich_editor' => 'basic']);

        $data = Tabs::make()->tabs([Tab::make('One')->schema([TextInput::make('a')])])->toData('edit', null);

        $this->assertSame('tab', $data->queryParam);
    }

    public function test_infolist_tabs_persist_like_form_tabs(): void
    {
        $data = InfolistTabs::make()->tabs([
            InfolistTab::make('Overview')->schema([TextEntry::make('name')]),
            InfolistTab::make('Facturación')->key('billing')->schema([TextEntry::make('plan')]),
        ])->toData('view', null);

        $this->assertSame('tab', $data->queryParam);
        $this->assertSame(['overview', 'billing'], array_map(fn ($tab) => $tab->urlKey, $data->schema));
    }

    public function test_wizard_steps_persist_in_the_step_param(): void
    {
        $data = Wizard::make()->steps([
            Step::make('Account')->schema([TextInput::make('email')]),
            Step::make('Plan')->key('billing')->schema([TextInput::make('plan')]),
        ])->toData('create', null);

        $this->assertSame('step', $data->queryParam);
        $this->assertSame(['account', 'billing'], array_map(fn ($step) => $step->urlKey, $data->schema));

        $off = Wizard::make()->persistStepInQueryString(false)->steps([
            Step::make('Account')->schema([TextInput::make('email')]),
        ])->toData('create', null);

        $this->assertNull($off->queryParam);
    }
}
