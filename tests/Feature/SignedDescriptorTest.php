<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Imports\Importer;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class SignedDescriptorTeam extends Model
{
    protected $table = 'sd_teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class SignedDescriptorUser extends Authenticatable
{
    protected $table = 'sd_users';

    public $timestamps = false;

    protected $guarded = [];

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(SignedDescriptorTeam::class, 'current_team_id');
    }
}

class SignedDescriptorExporter extends Exporter
{
    protected static ?string $model = SignedDescriptorTeam::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('id')];
    }
}

/**
 * Every signed endpoint descriptor is bound to the user it was minted for, the
 * team it was minted in, and an expiry.
 */
class SignedDescriptorTest extends TestCase
{
    private SignedDescriptorUser $member;

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.tables.token_ttl', 60);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $probe = static function (Request $request): string {
            $payload = SignedDescriptor::open((string) $request->input('token'));

            return $payload === null
                ? 'unreadable'
                : (SignedDescriptor::rejection($payload, $request)->name ?? 'ok');
        };

        $router->post('{current_team}/probe', $probe);
        $router->post('probe', $probe);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sd_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });

        Schema::create('sd_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('current_team_id');
        });

        $alpha = SignedDescriptorTeam::create(['slug' => 'alpha']);
        SignedDescriptorTeam::create(['slug' => 'beta']);

        $this->member = SignedDescriptorUser::create(['current_team_id' => $alpha->id]);
        $this->actingAs($this->member);
    }

    private function probe(string $token, ?string $team = 'alpha'): string
    {
        return $this->post($team === null ? '/probe' : "/{$team}/probe", ['token' => $token])
            ->assertOk()
            ->getContent();
    }

    public function test_a_sealed_descriptor_carries_the_user_team_and_expiry(): void
    {
        $this->freezeTime();

        $payload = SignedDescriptor::open(SignedDescriptor::seal(['model' => 'x']));

        $this->assertSame([
            'model'   => 'x',
            'user'    => $this->member->id,
            'team'    => 'alpha',
            'expires' => now()->getTimestamp() + 3600,
        ], $payload);
    }

    public function test_the_minting_user_may_use_it_in_the_same_team(): void
    {
        $this->assertSame('ok', $this->probe(SignedDescriptor::seal([])));
    }

    public function test_a_descriptor_minted_in_one_team_is_refused_in_another(): void
    {
        $this->assertSame('ForeignTeam', $this->probe(SignedDescriptor::seal([]), 'beta'));
    }

    public function test_another_user_cannot_replay_it(): void
    {
        $token = SignedDescriptor::seal([]);

        $this->actingAs(SignedDescriptorUser::create(['current_team_id' => 1]));

        $this->assertSame('ForeignUser', $this->probe($token));
    }

    public function test_it_expires(): void
    {
        $token = SignedDescriptor::seal([]);

        $this->travel(61)->minutes();

        $this->assertSame('Expired', $this->probe($token));
    }

    public function test_a_payload_without_binding_claims_is_refused(): void
    {
        $this->assertSame('Expired', $this->probe(Crypt::encrypt(['model' => 'x'])));
    }

    public function test_a_route_without_a_team_segment_checks_user_and_expiry_only(): void
    {
        $token = SignedDescriptor::seal([]);

        $this->assertSame('ok', $this->probe($token, null));

        $this->travel(61)->minutes();

        $this->assertSame('Expired', $this->probe($token, null));
    }

    public function test_the_team_claim_is_null_when_teams_are_off(): void
    {
        config()->set('kinetix.teams', false);

        $this->assertNull(SignedDescriptor::open(SignedDescriptor::seal([]))['team']);
    }

    public function test_class_tokens_are_bound_and_expire(): void
    {
        $token = SignedDescriptorExporter::token();

        $this->assertSame(SignedDescriptorExporter::class, SignedDescriptor::classFrom($token, Exporter::class));
        // An exporter token never resolves as an importer.
        $this->assertNull(SignedDescriptor::classFrom($token, Importer::class));

        $this->travel(61)->minutes();

        $this->assertNull(SignedDescriptor::classFrom($token, Exporter::class));
    }

    public function test_a_legacy_class_token_is_refused(): void
    {
        $this->assertNull(SignedDescriptor::classFrom(
            Crypt::encryptString(SignedDescriptorExporter::class),
            Exporter::class,
        ));
    }
}
