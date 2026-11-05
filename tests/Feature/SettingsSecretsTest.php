<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Settings\SettingsPage;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MailSecretsSettingsPage extends SettingsPage
{
    public function schema(): array
    {
        return [
            TextInput::make('host'),
            TextInput::make('password'),
        ];
    }

    public function group(): string
    {
        return 'mail';
    }

    public function encrypted(): array
    {
        return ['password'];
    }
}

class RequiredSecretSettingsPage extends SettingsPage
{
    public function schema(): array
    {
        return [TextInput::make('host'), TextInput::make('password')->required()];
    }

    public function group(): string
    {
        return 'mail';
    }

    public function encrypted(): array
    {
        return ['password'];
    }
}

class SecretsUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['api_token'];
}

/**
 * Encrypted settings are write-only: they never reach the browser, a team
 * page never shows (or compares against) the platform's global secret, and a
 * blank submit keeps what is stored.
 */
class SettingsSecretsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.settings.enabled', true);
        $app['config']->set('kinetix.settings.pages', [MailSecretsSettingsPage::class]);
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

        Gate::define('settings.manage', static fn (): bool => true);
    }

    /**
     * Serve the page as the given scope would.
     */
    private function servedAs(SettingsManager $settings): void
    {
        $this->app->instance(SettingsManager::class, $settings);
    }

    /**
     * @param  array<string, mixed>      $page
     * @return array<string, mixed>|null
     */
    private function field(array $page, string $name): ?array
    {
        foreach ($page['form']['schema'] as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field;
            }
        }

        return null;
    }

    public function test_an_encrypted_field_is_sent_empty_and_flagged_as_set(): void
    {
        KinetixSettings::set('mail.password', 's3cret', encrypted: true);
        KinetixSettings::set('mail.host', 'smtp.example.test');

        $page = (new MailSecretsSettingsPage)->toArray();

        $this->assertNull($page['form']['data']['password']);
        $this->assertTrue($this->field($page, 'password')['isWithheld']);
        $this->assertSame('smtp.example.test', $page['form']['data']['host']);
        $this->assertFalse($this->field($page, 'host')['isWithheld']);
        $this->assertStringNotContainsString('s3cret', (string) json_encode($page));
    }

    public function test_a_team_page_never_receives_the_global_secret(): void
    {
        config()->set('kinetix.teams', true);
        KinetixSettings::global()->set('mail.password', 'platform-secret', encrypted: true);

        $this->servedAs(app(SettingsManager::class)->forTeam(5));
        $page = (new MailSecretsSettingsPage)->toArray();

        $this->assertNull($page['form']['data']['password']);
        $this->assertFalse($this->field($page, 'password')['isWithheld']);
        $this->assertStringNotContainsString('platform-secret', (string) json_encode($page));

        // Runtime reads still inherit it.
        $this->assertSame('platform-secret', KinetixSettings::forTeam(5)->get('mail.password'));
    }

    public function test_a_blank_save_keeps_the_secret_and_a_new_value_replaces_it(): void
    {
        $page = new MailSecretsSettingsPage;
        KinetixSettings::set('mail.password', 'old', encrypted: true);

        $page->save(['host' => 'smtp', 'password' => '']);
        $this->assertSame('old', KinetixSettings::get('mail.password'));

        $page->save(['host' => 'smtp', 'password' => null]);
        $this->assertSame('old', KinetixSettings::get('mail.password'));

        $page->save(['host' => 'smtp', 'password' => 'new']);
        $this->assertSame('new', KinetixSettings::get('mail.password'));
    }

    public function test_submitting_the_global_secret_on_a_team_page_stores_a_team_copy(): void
    {
        config()->set('kinetix.teams', true);
        KinetixSettings::global()->set('mail.password', 'platform-secret', encrypted: true);

        $team = KinetixSettings::forTeam(5);
        (new MailSecretsSettingsPage)->save(['host' => null, 'password' => 'platform-secret'], $team);

        // Stored as the team's own: no "same as inherited" shortcut that would
        // tell the submitter they guessed the platform's secret.
        $this->assertArrayHasKey('mail.password', $team->own());
    }

    public function test_the_update_response_does_not_echo_the_secret(): void
    {
        $this->actingAs(SecretsUser::create(['name' => 'Admin']))
            ->putJson('/_kinetix/settings/mail', ['host' => 'smtp', 'password' => 'typed-secret'])
            ->assertOk()
            ->assertJsonPath('data.host', 'smtp')
            ->assertJsonMissingPath('data.password');

        $this->assertSame('typed-secret', KinetixSettings::get('mail.password'));
    }

    public function test_the_cache_never_holds_the_plaintext(): void
    {
        KinetixSettings::set('mail.password', 'plaintext-secret', encrypted: true);

        // Load the scope through the cache, as a fresh process would.
        $this->assertSame('plaintext-secret', (new SettingsManager)->get('mail.password'));

        $cached = Cache::get('kinetix.settings:v2:global');

        $this->assertIsArray($cached);
        $this->assertStringNotContainsString('plaintext-secret', serialize($cached));
    }

    public function test_a_hidden_model_attribute_is_flagged_only_when_it_has_a_value(): void
    {
        $form = fn (Model $record): array => Form::make($record)
            ->schema([TextInput::make('name'), TextInput::make('api_token')])
            ->fill($record)
            ->toArray();

        $flags = static fn (array $form): array => array_column($form['schema'], 'isWithheld', 'name');

        $withToken = $form(SecretsUser::create(['name' => 'A', 'api_token' => 'tok']));
        $this->assertSame(['name' => false, 'api_token' => true], $flags($withToken));
        $this->assertNull($withToken['data']['api_token']);

        $this->assertSame(['name' => false, 'api_token' => false], $flags($form(SecretsUser::create(['name' => 'B']))));
    }

    public function test_a_write_only_form_field_keeps_its_value_when_left_blank(): void
    {
        $form = Form::make()
            ->schema([TextInput::make('token')->required()])
            ->writeOnly(['token'])
            ->fill(['token' => 'stored']);

        $this->assertNull($form->toArray()['data']['token']);
        $this->assertTrue($form->toArray()['schema'][0]['isWithheld']);

        // Blank keeps the stored value: not validated, not written.
        $this->assertSame([], $form->validate(['token' => '']));
        $this->assertSame([], $form->getState(['token' => '']));
        $this->assertSame(['token' => 'next'], $form->getState(['token' => 'next']));

        // With nothing stored, a required write-only field is still required.
        $empty = Form::make()->schema([TextInput::make('token')->required()])->writeOnly(['token'])->fill([]);

        $this->assertFalse($empty->toArray()['schema'][0]['isWithheld']);
        $this->expectException(ValidationException::class);
        $empty->validate(['token' => '']);
    }

    public function test_a_team_page_saves_with_a_required_secret_it_inherits(): void
    {
        config()->set('kinetix.teams', true);
        KinetixSettings::global()->set('mail.password', 'platform-secret', encrypted: true);

        $team = KinetixSettings::forTeam(5);
        $page = new RequiredSecretSettingsPage;

        // Blank keeps inheriting the global secret: not required, not stored.
        $page->save(['host' => 'smtp.team', 'password' => ''], $team);

        $this->assertSame(['mail.host' => 'smtp.team'], $team->own());
        $this->assertSame('platform-secret', $team->get('mail.password'));

        // The inherited value isn't the team's own: not flagged as withheld.
        $this->servedAs($team);
        $field = $this->field($page->toArray(), 'password');
        $this->assertFalse($field['isWithheld']);
    }

    public function test_a_required_secret_with_no_value_anywhere_is_still_required(): void
    {
        config()->set('kinetix.teams', true);

        $this->expectException(ValidationException::class);

        (new RequiredSecretSettingsPage)->save(['host' => 'smtp', 'password' => ''], KinetixSettings::forTeam(5));
    }

    public function test_the_legacy_cache_entry_with_decrypted_values_is_dropped(): void
    {
        Cache::forever('kinetix.settings:global', ['mail.password' => 'decrypted-secret']);

        (new SettingsManager)->get('mail.password');

        $this->assertFalse(Cache::has('kinetix.settings:global'));
        $this->assertTrue(Cache::has('kinetix.settings:v2:global'));
    }
}
