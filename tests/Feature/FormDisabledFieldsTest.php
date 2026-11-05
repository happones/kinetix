<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\SettingsPage;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

class DisabledFieldAccount extends Model
{
    protected $table = 'disabled_field_accounts';

    public $timestamps = false;

    protected $guarded = [];
}

class LockedSettingsPage extends SettingsPage
{
    public function schema(): array
    {
        return [
            TextInput::make('name'),
            TextInput::make('license')->disabled(),
        ];
    }

    public function group(): string
    {
        return 'locked';
    }
}

/**
 * A disabled field takes no input on the server either: whatever the browser
 * sends for it is ignored unless the field opted into savedWhenDisabled().
 */
class FormDisabledFieldsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('disabled_field_accounts', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('plan')->nullable();
            $table->string('status')->nullable();
            $table->string('note')->nullable();
        });

        Schema::create('kinetix_settings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
            $table->unique(['team_id', 'key']);
        });
    }

    public function test_a_tampered_disabled_field_is_ignored_on_edit(): void
    {
        $account = DisabledFieldAccount::create(['name' => 'Acme', 'plan' => 'free']);

        $form = Form::make($account)
            ->schema([TextInput::make('name'), TextInput::make('plan')->disabled()->required()->rules(['in:free'])])
            ->fill($account);

        $input = ['name' => 'Acme Inc', 'plan' => 'enterprise'];

        $this->assertSame(['name' => 'Acme Inc'], $form->validate($input));
        $this->assertSame(['name' => 'Acme Inc'], $form->getState($input));
    }

    public function test_a_disabled_closure_is_evaluated_with_the_record(): void
    {
        $account = DisabledFieldAccount::create(['name' => 'Acme', 'plan' => 'pro']);

        $form = Form::make($account)
            ->schema([TextInput::make('plan')->disabled(static fn (?Model $record): bool => $record?->plan === 'pro')])
            ->fill($account);

        $this->assertSame([], $form->getState(['plan' => 'free']));
    }

    public function test_create_uses_the_default_not_the_submitted_value(): void
    {
        $form = Form::make()
            ->schema([TextInput::make('plan')->disabled()->default('free')])
            ->operation('create')
            ->fill([]);

        $this->assertSame(['plan' => 'free'], $form->getState(['plan' => 'enterprise']));
    }

    public function test_a_holding_disabled_when_condition_ignores_the_value(): void
    {
        $account = DisabledFieldAccount::create(['status' => 'open', 'note' => 'original']);

        $form = fn (): Form => Form::make($account)
            ->schema([TextInput::make('status'), TextInput::make('note')->disabledWhen('status', 'closed')])
            ->fill($account);

        $this->assertSame(['status' => 'closed'], $form()->getState(['status' => 'closed', 'note' => 'tampered']));
        $this->assertSame(['status' => 'open', 'note' => 'edited'], $form()->getState(['status' => 'open', 'note' => 'edited']));
    }

    public function test_saved_when_disabled_saves_the_submitted_value(): void
    {
        $account = DisabledFieldAccount::create(['plan' => 'free']);

        $form = Form::make($account)
            ->schema([TextInput::make('plan')->disabled()->savedWhenDisabled()])
            ->fill($account);

        $this->assertSame(['plan' => 'computed'], $form->getState(['plan' => 'computed']));
    }

    public function test_a_disabled_field_on_a_settings_page_keeps_its_value(): void
    {
        KinetixSettings::set('locked.license', 'LIC-1');

        (new LockedSettingsPage)->save(['name' => 'Acme', 'license' => 'LIC-FORGED']);

        $this->assertSame('LIC-1', KinetixSettings::get('locked.license'));
        $this->assertSame('Acme', KinetixSettings::get('locked.name'));
    }
}
