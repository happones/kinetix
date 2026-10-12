<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\HostKeys;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UuidKeyUser extends Model
{
    use HasUuids;
}

class UlidKeyUser extends Model
{
    use HasUlids;
}

/** Bigint-keyed, with a public ULID next to the id (a route key, say). */
class PublicUlidUser extends Model
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }
}

class PublicUlidTeam extends Model
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }
}

class UserWithPublicUlidTeams extends Model
{
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(PublicUlidTeam::class);
    }
}

/** A UUID-keyed model that cannot be built without arguments. */
class UnbuildableUuidUser extends Model
{
    use HasUuids;

    public function __construct(string $required)
    {
        parent::__construct();
    }
}

class StringKeyUser extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;
}

class UuidKeyTeam extends Model
{
    use HasUuids;
}

class UserWithUuidTeams extends Model
{
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(UuidKeyTeam::class);
    }
}

/**
 * Migrations type host-model reference columns after the model they point to —
 * a UUID/ULID User must not end up referenced by an unsignedBigInteger column.
 */
class HostKeysTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('host_keys_probe');

        parent::tearDown();
    }

    private function setUserModel(string $class): void
    {
        config()->set('auth.providers.users.model', $class);
    }

    /** Create a probe table and return the resolved column type of `user_id`. */
    private function probeColumnType(): string
    {
        Schema::dropIfExists('host_keys_probe');
        Schema::create('host_keys_probe', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->index();
        });

        return Schema::getColumnType('host_keys_probe', 'user_id');
    }

    public function test_bigint_users_keep_the_historical_column_type(): void
    {
        // Testbench's default user model has integer keys.
        $this->assertSame('bigint', HostKeys::type('user'));
        $this->assertSame('integer', $this->probeColumnType());
    }

    public function test_a_uuid_user_model_is_detected(): void
    {
        $this->setUserModel(UuidKeyUser::class);

        $this->assertSame('uuid', HostKeys::type('user'));
        // sqlite stores uuids as varchar — the point is it is not an integer.
        $this->assertSame('varchar', $this->probeColumnType());
    }

    public function test_a_ulid_user_model_is_detected(): void
    {
        $this->setUserModel(UlidKeyUser::class);

        $this->assertSame('ulid', HostKeys::type('user'));
        $this->assertSame('varchar', $this->probeColumnType());
    }

    public function test_a_public_ulid_next_to_a_bigint_key_is_not_mistaken_for_the_key(): void
    {
        $this->setUserModel(PublicUlidUser::class);

        $this->assertSame('bigint', HostKeys::type('user'));
        $this->assertSame('integer', $this->probeColumnType());
    }

    public function test_a_team_with_a_public_ulid_keeps_its_bigint_key(): void
    {
        $this->setUserModel(UserWithPublicUlidTeams::class);

        $this->assertSame('bigint', HostKeys::type('team'));
    }

    public function test_a_model_that_cannot_be_built_is_judged_by_its_traits(): void
    {
        $this->setUserModel(UnbuildableUuidUser::class);

        $this->assertSame('uuid', HostKeys::type('user'));
    }

    public function test_the_billable_morph_of_the_usage_tables_follows_the_morph_type(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000027_create_kinetix_usage_tables.php';

        config()->set('kinetix.key_types.morph', 'uuid');
        $migration->down();
        $migration->up();

        try {
            $this->assertSame('varchar', Schema::getColumnType('kinetix_usage', 'billable_id'));
            $this->assertSame('varchar', Schema::getColumnType('kinetix_credits', 'billable_id'));
        } finally {
            config()->set('kinetix.key_types.morph', 'bigint');
            $migration->down();
            $migration->up();
        }
    }

    public function test_a_plain_string_key_model_is_detected(): void
    {
        $this->setUserModel(StringKeyUser::class);

        $this->assertSame('string', HostKeys::type('user'));
        $this->assertSame('varchar', $this->probeColumnType());
    }

    public function test_an_explicit_config_pin_beats_detection(): void
    {
        $this->setUserModel(UuidKeyUser::class);
        config()->set('kinetix.key_types.user', 'bigint');

        $this->assertSame('bigint', HostKeys::type('user'));
    }

    public function test_the_team_type_derives_from_the_users_teams_relation(): void
    {
        $this->setUserModel(UserWithUuidTeams::class);

        $this->assertSame('uuid', HostKeys::type('team'));
    }

    public function test_teams_fall_back_to_bigint_without_an_inspectable_relation(): void
    {
        $this->setUserModel(UuidKeyUser::class); // no teams() relation

        $this->assertSame('bigint', HostKeys::type('team'));
    }

    public function test_morphs_follow_config_and_default_to_bigint(): void
    {
        $this->assertSame('bigint', HostKeys::type('morph'));

        config()->set('kinetix.key_types.morph', 'ulid');
        $this->assertSame('ulid', HostKeys::type('morph'));
    }

    public function test_a_missing_model_class_falls_back_to_bigint(): void
    {
        $this->setUserModel('App\\Models\\DoesNotExist');

        $this->assertSame('bigint', HostKeys::type('user'));
    }
}
