<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Teams\TeamSwitcherManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;

class TeamSwitcherTeam extends Model
{
    protected $table = 'teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

enum TeamSwitcherIndustry: string
{
    case Retail     = 'retail';
    case Healthcare = 'healthcare';

    public function getColor(): string
    {
        return match ($this) {
            self::Retail     => '#16a34a',
            self::Healthcare => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Retail     => 'store',
            self::Healthcare => 'heart-pulse',
        };
    }
}

class TeamSwitcherIdentity
{
    public static function logo(Model $team): ?string
    {
        return $team->getAttribute('slug') === 'acme' ? 'https://cdn.example.com/acme.png' : null;
    }

    public static function icon(Model $team): string
    {
        return 'sparkles';
    }

    public static function landing(Model $team): string
    {
        return '/onboarding/'.$team->getAttribute('slug');
    }
}

class TeamSwitcherUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class TeamSwitcherTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.team_switcher.enabled', true);
        $app['config']->set('kinetix.team_switcher.switch_route', 'teams.switch');
        $app['config']->set('kinetix.team_switcher.create_route', 'teams.create');
        $app['config']->set('auth.providers.users.model', TeamSwitcherUser::class);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('teams/{team}/switch', fn () => 'ok')->name('teams.switch');
        $router->get('teams/create', fn () => 'ok')->name('teams.create');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug');
            $table->string('logo')->nullable();
            $table->string('industry')->nullable();
            $table->string('plan')->nullable();
        });
    }

    private function actingUserWithTeams(): TeamSwitcherUser
    {
        $acme   = TeamSwitcherTeam::create(['name' => 'Acme', 'slug' => 'acme']);
        $globex = TeamSwitcherTeam::create(['name' => 'Globex', 'slug' => 'globex']);

        $user = TeamSwitcherUser::create(['name' => 'Ada']);
        $user->setRelation('teams', collect([$acme, $globex]));
        $user->setRelation('currentTeam', $acme);

        $this->actingAs($user);

        return $user;
    }

    public function test_payload_lists_teams_with_switch_urls_and_marks_the_current(): void
    {
        $this->actingUserWithTeams();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertTrue($payload['enabled']);
        $this->assertCount(2, $payload['teams']);
        $this->assertSame('Acme', $payload['teams'][0]['name']);
        $this->assertTrue($payload['teams'][0]['current']);
        $this->assertFalse($payload['teams'][1]['current']);
        $this->assertStringEndsWith('/teams/globex/switch', $payload['teams'][1]['url']);
        $this->assertSame('globex', $payload['teams'][1]['key']);
        $this->assertSame('get', $payload['switchMethod']);
        $this->assertSame('Acme', $payload['current']['name']);
        $this->assertStringEndsWith('/teams/create', $payload['createUrl']);
    }

    public function test_a_post_only_switch_route_is_visited_with_post(): void
    {
        $this->app['router']->post('settings/teams/{team}/switch', fn () => 'ok')->name('teams.post-switch');
        $this->app['router']->getRoutes()->refreshNameLookups();
        config()->set('kinetix.team_switcher.switch_route', 'teams.post-switch');

        $this->actingUserWithTeams();

        $this->assertSame('post', app(TeamSwitcherManager::class)->payload()['switchMethod']);
    }

    public function test_payload_is_empty_when_disabled(): void
    {
        config()->set('kinetix.team_switcher.enabled', false);
        $this->actingUserWithTeams();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertFalse($payload['enabled']);
        $this->assertSame([], $payload['teams']);
    }

    public function test_payload_is_empty_for_guests(): void
    {
        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertFalse($payload['enabled']);
        $this->assertNull($payload['current']);
    }

    public function test_switch_url_is_null_when_the_route_is_missing(): void
    {
        config()->set('kinetix.team_switcher.switch_route', 'nonexistent.route');
        $this->actingUserWithTeams();

        $payload = app(TeamSwitcherManager::class)->payload();

        $this->assertNull($payload['teams'][0]['url']);
    }

    /**
     * @param  array<string, mixed>             $attributes
     * @return array<int, array<string, mixed>>
     */
    private function teamsWith(array $attributes): array
    {
        $acme   = TeamSwitcherTeam::create(['name' => 'Acme Coffee Co', 'slug' => 'acme', ...($attributes['acme'] ?? [])]);
        $globex = TeamSwitcherTeam::create(['name' => 'globex', 'slug' => 'globex', ...($attributes['globex'] ?? [])]);

        $user = TeamSwitcherUser::create(['name' => 'Ada']);
        $user->setRelation('teams', collect([$acme, $globex]));
        $user->setRelation('currentTeam', $acme);
        $this->actingAs($user);

        return app(TeamSwitcherManager::class)->payload()['teams'];
    }

    public function test_teams_carry_initials_and_nothing_else_until_turned_on(): void
    {
        $teams = $this->teamsWith(['acme' => ['logo' => 'logos/acme.png', 'industry' => 'retail']]);

        $this->assertSame('AC', $teams[0]['initials']);
        $this->assertSame('G', $teams[1]['initials']);
        $this->assertNull($teams[0]['logo']);
        $this->assertNull($teams[0]['logoIcon']);
        $this->assertNull($teams[0]['accent']);
        $this->assertNull($teams[0]['icon']);
    }

    public function test_a_logo_is_a_url_a_path_on_a_disk_or_a_resolvers_answer(): void
    {
        config(['kinetix.team_switcher.logo' => ['enabled' => true, 'attribute' => 'logo', 'disk' => 'public']]);
        config(['filesystems.disks.public.url' => 'https://app.test/storage']);

        $teams = $this->teamsWith([
            'acme'   => ['logo' => 'https://cdn.example.com/a.png'],
            'globex' => ['logo' => 'logos/globex.png'],
        ]);

        $this->assertSame('https://cdn.example.com/a.png', $teams[0]['logo']);
        $this->assertSame('https://app.test/storage/logos/globex.png', $teams[1]['logo']);

        TeamSwitcherTeam::query()->delete();
        config(['kinetix.team_switcher.logo.resolver' => [TeamSwitcherIdentity::class, 'logo']]);
        $teams = $this->teamsWith([]);

        $this->assertSame('https://cdn.example.com/acme.png', $teams[0]['logo']);
        $this->assertNull($teams[1]['logo']);
    }

    public function test_a_team_without_a_logo_shows_the_default_icon_or_image(): void
    {
        config(['kinetix.team_switcher.logo' => ['enabled' => true, 'attribute' => 'logo', 'default' => 'building-2']]);
        $teams = $this->teamsWith(['acme' => ['logo' => 'https://cdn.example.com/a.png']]);

        // With a logo, the default icon still comes along: it stands in if the image fails.
        $this->assertSame(['https://cdn.example.com/a.png', 'building-2'], [$teams[0]['logo'], $teams[0]['logoIcon']]);
        $this->assertSame([null, 'building-2'], [$teams[1]['logo'], $teams[1]['logoIcon']]);

        // An image as the default: a path with an image extension on the disk.
        TeamSwitcherTeam::query()->delete();
        config(['kinetix.team_switcher.logo.default' => 'brand/team.svg', 'kinetix.team_switcher.logo.disk' => 'public']);
        config(['filesystems.disks.public.url' => 'https://app.test/storage']);
        $teams = $this->teamsWith([]);

        $this->assertSame(['https://app.test/storage/brand/team.svg', null], [$teams[1]['logo'], $teams[1]['logoIcon']]);
    }

    public function test_the_indicator_color_comes_from_an_enum_a_map_or_a_written_color(): void
    {
        config(['kinetix.team_switcher.indicator' => ['enabled' => true, 'attribute' => 'industry']]);

        // Cast to the enum: its getColor() answers; a status name becomes its token.
        $teams = (function (): array {
            $acme = (new class extends TeamSwitcherTeam
            {
                protected $casts = ['industry' => TeamSwitcherIndustry::class];
            })->forceFill(['id' => 1, 'name' => 'Acme', 'slug' => 'acme', 'industry' => 'retail']);
            $health = (new class extends TeamSwitcherTeam
            {
                protected $casts = ['industry' => TeamSwitcherIndustry::class];
            })->forceFill(['id' => 2, 'name' => 'Clinic', 'slug' => 'clinic', 'industry' => 'healthcare']);

            $user = TeamSwitcherUser::create(['name' => 'Ada']);
            $user->setRelation('teams', collect([$acme, $health]));
            $user->setRelation('currentTeam', $acme);
            $this->actingAs($user);

            return app(TeamSwitcherManager::class)->payload()['teams'];
        })();

        $this->assertSame('#16a34a', $teams[0]['accent']);
        $this->assertSame('var(--color-info)', $teams[1]['accent']);
        // A dot by default: the enum's getIcon() only names the icon when asked.
        $this->assertNull($teams[0]['icon']);

        // A plain value through the map; something that isn't a color is dropped.
        config(['kinetix.team_switcher.indicator' => [
            'enabled' => true, 'attribute' => 'plan', 'colors' => ['pro' => 'oklch(0.6 0.2 300)', 'free' => 'gray'],
        ]]);
        $teams = $this->teamsWith(['acme' => ['plan' => 'pro'], 'globex' => ['plan' => 'enterprise']]);

        $this->assertSame('oklch(0.6 0.2 300)', $teams[0]['accent']);
        $this->assertNull($teams[1]['accent']);
    }

    public function test_an_icon_can_replace_the_indicators_dot(): void
    {
        // Per type through the map, else the icon set for every team.
        config(['kinetix.team_switcher.indicator' => [
            'enabled' => true, 'attribute' => 'industry', 'icons' => ['retail' => 'store'], 'icon' => 'circle-dot',
        ]]);
        $teams = $this->teamsWith(['acme' => ['industry' => 'retail'], 'globex' => ['industry' => 'healthcare']]);

        $this->assertSame('store', $teams[0]['icon']);
        $this->assertSame('circle-dot', $teams[1]['icon']);

        // An enum's own getIcon() with `enum_icons`; `icon_resolver` overrides all.
        config(['kinetix.team_switcher.indicator' => ['enabled' => true, 'attribute' => 'industry', 'enum_icons' => true]]);
        $acme = (new class extends TeamSwitcherTeam
        {
            protected $casts = ['industry' => TeamSwitcherIndustry::class];
        })->forceFill(['id' => 1, 'name' => 'Acme', 'slug' => 'acme', 'industry' => 'retail']);
        $user = TeamSwitcherUser::create(['name' => 'Ada']);
        $user->setRelation('teams', collect([$acme]));
        $user->setRelation('currentTeam', $acme);
        $this->actingAs($user);

        $this->assertSame('store', app(TeamSwitcherManager::class)->payload()['teams'][0]['icon']);

        config(['kinetix.team_switcher.indicator.icon_resolver' => [TeamSwitcherIdentity::class, 'icon']]);
        $this->assertSame('sparkles', app(TeamSwitcherManager::class)->payload()['teams'][0]['icon']);
    }

    public function test_the_switcher_speaks_in_the_apps_terms(): void
    {
        config(['kinetix.team_switcher.labels' => ['create' => 'New account', 'switch' => 'Switch account']]);
        $this->teamsWith([]);

        $labels = app(TeamSwitcherManager::class)->payload()['labels'];

        $this->assertSame('New account', $labels['create']);
        $this->assertSame('Switch account', $labels['switch']);
        // Unset, the package's own words (read from the package itself).
        $this->assertSame('Select a team', $labels['select']);
    }

    public function test_after_creating_a_team_the_app_decides_where_to_land(): void
    {
        $team = TeamSwitcherTeam::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->assertSame('/teams/acme/edit', KinetixTeams::redirectAfterCreate($team, '/teams/acme/edit'));

        config(['kinetix.team_switcher.redirect_after_create' => 'teams.switch']);
        $this->assertStringEndsWith('/teams/acme/switch', KinetixTeams::redirectAfterCreate($team, '/fallback'));

        config(['kinetix.team_switcher.redirect_after_create' => [TeamSwitcherIdentity::class, 'landing']]);
        $this->assertSame('/onboarding/acme', KinetixTeams::redirectAfterCreate($team, '/fallback'));
    }
}
