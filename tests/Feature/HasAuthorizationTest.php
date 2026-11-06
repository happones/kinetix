<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Closure;
use Happones\Kinetix\Support\Concerns\HasAuthorization;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

class HasAuthorizationUser extends Authenticatable {}

class HasAuthorizationPost extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['published' => 'bool'];
}

/**
 * The argument forms of authorize() live on the shared HasAuthorization
 * concern, so they are tested once here instead of through every host
 * (form fields, infolist entries, actions, columns). Each host keeps its own
 * "stripped when unauthorized" test.
 */
class HasAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('editPost', fn ($user, ?HasAuthorizationPost $post = null) => ! $post?->published);

        $this->actingAs(new HasAuthorizationUser);
    }

    /**
     * @return array<string, array{Closure(object): object, bool}>
     */
    public static function authorizeForms(): array
    {
        return [
            'ability, denying subject'  => [fn (object $item) => $item->authorize('editPost', new HasAuthorizationPost(['published' => true])), false],
            'ability, allowing subject' => [fn (object $item) => $item->authorize('editPost', new HasAuthorizationPost(['published' => false])), true],
            'closure returning false'   => [fn (object $item) => $item->authorize(fn () => false), false],
            'closure returning true'    => [fn (object $item) => $item->authorize(fn () => true), true],
            'bare false'                => [fn (object $item) => $item->authorize(false), false],
            'bare true'                 => [fn (object $item) => $item->authorize(true), true],
        ];
    }

    /**
     * @param Closure(object): object $configure
     */
    #[DataProvider('authorizeForms')]
    public function test_authorize_accepts_an_ability_with_a_subject_a_closure_or_a_boolean(Closure $configure, bool $expected): void
    {
        $item = $configure(new class
        {
            use HasAuthorization;
        });

        $this->assertSame($expected, $item->shouldRender());
    }
}
