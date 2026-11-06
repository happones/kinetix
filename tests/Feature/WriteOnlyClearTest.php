<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\Setting;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * A write-only value with its own stored value can be removed on purpose
 * (`Form::CLEAR_SENTINEL`); everywhere else the sentinel means "unchanged"
 * and is never stored.
 */
class WriteOnlyClearTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.settings.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('api_token')->nullable();
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

    public function test_a_global_secret_can_be_removed(): void
    {
        KinetixSettings::set('mail.password', 's3cret', encrypted: true);

        (new MailSecretsSettingsPage)->save(['host' => 'smtp', 'password' => Form::CLEAR_SENTINEL]);

        $this->assertNull(KinetixSettings::get('mail.password'));
        $this->assertSame(0, Setting::query()->where('key', 'mail.password')->count());
        $this->assertSentinelNeverStored();
    }

    public function test_a_required_secret_cannot_be_cleared(): void
    {
        KinetixSettings::set('mail.password', 's3cret', encrypted: true);

        (new RequiredSecretSettingsPage)->save(['host' => 'smtp', 'password' => Form::CLEAR_SENTINEL]);

        $this->assertSame('s3cret', KinetixSettings::get('mail.password'));
        $this->assertSentinelNeverStored();
    }

    public function test_an_inherited_secret_cannot_be_cleared_from_a_team_page(): void
    {
        config()->set('kinetix.teams', true);
        KinetixSettings::global()->set('mail.password', 'platform-secret', encrypted: true);

        (new MailSecretsSettingsPage)->save(['host' => null, 'password' => Form::CLEAR_SENTINEL], KinetixSettings::forTeam(5));

        $this->assertSame('platform-secret', KinetixSettings::global()->get('mail.password'));
        $this->assertSame(0, Setting::query()->where('team_id', 5)->where('key', 'mail.password')->count());
        $this->assertSentinelNeverStored();
    }

    public function test_a_team_secret_can_be_removed_and_the_team_inherits_again(): void
    {
        config()->set('kinetix.teams', true);
        KinetixSettings::global()->set('mail.password', 'platform-secret', encrypted: true);
        KinetixSettings::forTeam(5)->set('mail.password', 'team-secret', encrypted: true);

        (new MailSecretsSettingsPage)->save(['host' => null, 'password' => Form::CLEAR_SENTINEL], KinetixSettings::forTeam(5));

        $this->assertSame(0, Setting::query()->where('team_id', 5)->where('key', 'mail.password')->count());
        $this->assertSame('platform-secret', KinetixSettings::forTeam(5)->get('mail.password'));
    }

    public function test_a_hidden_model_attribute_keeps_its_value_on_the_sentinel(): void
    {
        $user = SecretsUser::create(['name' => 'A', 'api_token' => 'tok']);

        $state = Form::make($user)
            ->schema([TextInput::make('name'), TextInput::make('api_token')])
            ->getState(['name' => 'A', 'api_token' => Form::CLEAR_SENTINEL]);

        $this->assertSame(['name' => 'A'], $state);
    }

    public function test_the_sentinel_is_never_kept_for_any_field_type(): void
    {
        foreach ([TextInput::make('secret'), Select::make('secret')->options(['a' => 'A']), Toggle::make('secret')] as $field) {
            $required = Form::make()->schema([(clone $field)->required()])->writeOnly(['secret'])->fill(['secret' => 'stored']);
            $this->assertSame([], $required->getState(['secret' => Form::CLEAR_SENTINEL]));

            $empty = Form::make()->schema([clone $field])->writeOnly(['secret'])->fill([]);
            $this->assertSame([], $empty->getState(['secret' => Form::CLEAR_SENTINEL]));

            $clearable = Form::make()->schema([clone $field])->writeOnly(['secret'])->fill(['secret' => 'stored']);
            $this->assertArrayHasKey('secret', $clearable->getState(['secret' => Form::CLEAR_SENTINEL]));
            $this->assertNotSame(Form::CLEAR_SENTINEL, $clearable->getState(['secret' => Form::CLEAR_SENTINEL])['secret']);
        }
    }

    public function test_is_clearable_only_for_an_own_optional_write_only_value(): void
    {
        $flags = static fn (Form $form): array => array_column($form->toArray()['schema'], 'isClearable', 'name');

        $this->assertSame(['token' => true], $flags(Form::make()->schema([TextInput::make('token')])->writeOnly(['token'])->fill(['token' => 'x'])));
        $this->assertSame(['token' => false], $flags(Form::make()->schema([TextInput::make('token')->required()])->writeOnly(['token'])->fill(['token' => 'x'])));
        $this->assertSame(['token' => false], $flags(Form::make()->schema([TextInput::make('token')])->writeOnly(['token'])->fill([])));
        $this->assertSame(['token' => false], $flags(Form::make()->schema([TextInput::make('token')])->writeOnly(['token'])->inheritingWriteOnly(['token'])->fill(['token' => 'x'])));
        $this->assertSame(['name' => false, 'api_token' => false], $flags(
            Form::make($user = SecretsUser::create(['name' => 'A', 'api_token' => 'tok']))->schema([TextInput::make('name'), TextInput::make('api_token')])->fill($user),
        ));
    }

    public function test_any_rule_that_can_require_the_field_makes_it_not_clearable(): void
    {
        $clearable = static fn (array $rules): bool => Form::make()
            ->schema([TextInput::make('token')->rules($rules), TextInput::make('other')])
            ->writeOnly(['token'])
            ->fill(['token' => 'x'])
            ->toArray()['schema'][0]['isClearable'];

        $this->assertFalse($clearable(['string|required|max:40']));
        $this->assertFalse($clearable(['required_with:other']));
        $this->assertFalse($clearable(['required_if:other,1']));
        $this->assertFalse($clearable([Rule::requiredIf(true)]));
        $this->assertFalse($clearable([Rule::requiredIf(false)]));
        $this->assertTrue($clearable(['string', 'max:40']));
    }

    public function test_the_sentinel_skips_validation_when_it_clears(): void
    {
        $form = Form::make()->schema([TextInput::make('token')->rules(['min:40'])])->writeOnly(['token'])->fill(['token' => 'stored']);

        $this->assertSame([], $form->getValidationRules(['token' => Form::CLEAR_SENTINEL]));
    }

    public function test_a_blank_submit_still_keeps_the_value(): void
    {
        KinetixSettings::set('mail.password', 's3cret', encrypted: true);

        (new MailSecretsSettingsPage)->save(['host' => 'smtp', 'password' => '']);

        $this->assertSame('s3cret', KinetixSettings::get('mail.password'));
    }

    private function assertSentinelNeverStored(): void
    {
        foreach (DB::table('kinetix_settings')->pluck('value') as $value) {
            $this->assertStringNotContainsString(Form::CLEAR_SENTINEL, (string) $value);
        }

        foreach (Setting::query()->get() as $setting) {
            $this->assertNotSame(Form::CLEAR_SENTINEL, $setting->getAttribute('value'));
        }
    }
}
