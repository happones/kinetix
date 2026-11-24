<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\FileUpload;
use Happones\Kinetix\Forms\UploadDirectory;
use Happones\Kinetix\Media\KinetixMedia;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionServiceProvider;

/**
 * With teams on, uploads are foldered per team (empresa) and per user: a file
 * uploaded in one team is never attached to a record in another, and a request
 * without a team uploads and adopts nothing.
 */
class UploadEndpointTeamsTest extends TestCase
{
    use ResolvesTeamRoutes;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function field(): string
    {
        return (string) FileUpload::make('photo')->directory('products')->toData('create')?->uploadToken;
    }

    public function test_uploads_are_foldered_per_team_and_never_adopted_across_teams(): void
    {
        $this->actingAs(new UploadUser(['id' => 7, 'current_team_id' => 5]));
        $this->withTeamSegment('5');

        $response = $this->postJson(route('kinetix.uploads.store', ['current_team' => '5']), [
            'token' => $this->field(),
            'file'  => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ])->assertOk()->json();

        $this->assertStringStartsWith('products/'.md5('team|5').'/'.md5('7').'/', $response['path']);

        // Same user, now working in team 6: the team-5 upload isn't theirs to attach here.
        $this->withTeamSegment('6');
        $this->actingAs(new UploadUser(['id' => 7, 'current_team_id' => 6]));
        $this->assertSame([], KinetixMedia::keep([['path' => $response['path']]], directory: 'products'));

        // Back in team 5 it is.
        $this->withTeamSegment('5');
        $this->actingAs(new UploadUser(['id' => 7, 'current_team_id' => 5]));
        $this->assertSame([$response['path']], array_column(KinetixMedia::keep([['path' => $response['path']]], directory: 'products'), 'path'));
    }

    public function test_a_token_minted_in_one_team_uploads_nothing_in_another(): void
    {
        $this->actingAs(new UploadUser(['id' => 7, 'current_team_id' => 5]));
        $this->withTeamSegment('5');
        $token = $this->field();

        $this->actingAs(new UploadUser(['id' => 7, 'current_team_id' => 6]));
        $this->postJson(route('kinetix.uploads.store', ['current_team' => '6']), [
            'token' => $token,
            'file'  => UploadedFile::fake()->image('photo.jpg', 10, 10),
        ])->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_without_a_team_nothing_is_adopted(): void
    {
        $this->actingAs(new UploadUser(['id' => 7]));
        $this->withoutTeamSegment();
        Storage::disk('public')->put('products/'.md5('7').'/legacy.jpg', 'x');

        $this->assertTrue(UploadDirectory::teamMissing());
        $this->assertSame([], KinetixMedia::keep([['path' => 'products/'.md5('7').'/legacy.jpg']], directory: 'products'));
    }

    public function test_uploads_can_opt_out_of_team_folders(): void
    {
        config()->set('kinetix.filesystem.teams', false);
        $this->actingAs(new UploadUser(['id' => 7, 'current_team_id' => 5]));
        $this->withTeamSegment('5');

        $this->assertFalse(UploadDirectory::teamMissing());
        $this->assertSame('products/'.md5('7'), UploadDirectory::current('products'));
    }
}
