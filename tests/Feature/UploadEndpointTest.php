<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\FileUpload;
use Happones\Kinetix\Forms\UploadDirectory;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tables\Columns\ImageColumn;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadUser extends User
{
    protected $guarded = [];
}

class UploadRecord extends Model
{
    protected $guarded = [];
}

/**
 * The upload endpoints behind FileUpload / MediaLibrary: storage on any disk,
 * URLs resolved on that disk (temporary ones for private files), deletion only
 * by the session that uploaded, a per-user throttle and team-scoped folders.
 */
class UploadEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3');
        $this->actingAs(new UploadUser(['id' => 7]));
    }

    private function field(?FileUpload $field = null): string
    {
        return (string) ($field ?? FileUpload::make('photo')->directory('products'))->toData('create')?->uploadToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $token, string $name = 'photo.jpg'): array
    {
        return $this->postJson(route('kinetix.uploads.store'), [
            'token' => $token,
            'file'  => UploadedFile::fake()->image($name, 10, 10),
        ])->assertOk()->json();
    }

    public function test_an_upload_lands_in_the_users_folder_and_answers_its_disk_url_and_a_receipt(): void
    {
        $response = $this->upload($this->field());

        $this->assertStringStartsWith('products/'.md5('7').'/', $response['path']);
        Storage::disk('public')->assertExists($response['path']);
        $this->assertSame(Storage::disk('public')->url($response['path']), $response['url']);

        $receipt = SignedDescriptor::open($response['receipt']);
        $this->assertSame(DescriptorAudience::UploadReceipt->value, $receipt['aud'] ?? null);
        $this->assertSame($response['path'], $receipt['path'] ?? null);
        $this->assertSame('public', $receipt['disk'] ?? null);
    }

    public function test_a_private_field_stores_privately_on_its_disk_and_answers_a_temporary_url(): void
    {
        $response = $this->upload($this->field(FileUpload::make('id_card')->disk('s3')->visibility('private')));

        Storage::disk('s3')->assertExists($response['path']);
        Storage::disk('public')->assertMissing($response['path']);
        $this->assertSame('private', Storage::disk('s3')->getVisibility($response['path']));
        $this->assertStringContainsString('?expiration=', $response['url']);
    }

    public function test_only_the_receipt_of_this_users_upload_deletes_it(): void
    {
        $response = $this->upload($this->field());
        $path     = $response['path'];

        // The old shape (field token + a path) deletes nothing any more.
        $this->postJson(route('kinetix.uploads.delete'), ['token' => $this->field(), 'path' => $path])->assertStatus(422);
        Storage::disk('public')->assertExists($path);

        // Another user can't spend this user's receipt.
        $this->actingAs(new UploadUser(['id' => 8]));
        $this->postJson(route('kinetix.uploads.delete'), ['receipt' => $response['receipt']])->assertForbidden();
        Storage::disk('public')->assertExists($path);

        // A field token is not a receipt.
        $this->actingAs(new UploadUser(['id' => 7]));
        $this->postJson(route('kinetix.uploads.delete'), ['receipt' => $this->field()])->assertForbidden();
        Storage::disk('public')->assertExists($path);

        $this->postJson(route('kinetix.uploads.delete'), ['receipt' => $response['receipt']])->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_stored_file_without_a_receipt_cant_be_deleted_even_with_scoping_off(): void
    {
        config()->set('kinetix.filesystem.scope_uploads_by_user', false);
        Storage::disk('public')->put('products/someone-elses.jpg', 'x');

        $forged = SignedDescriptor::seal(['disk' => 'public', 'path' => 'products/someone-elses.jpg'], DescriptorAudience::Upload);

        $this->postJson(route('kinetix.uploads.delete'), ['receipt' => $forged])->assertForbidden();
        $this->postJson(route('kinetix.uploads.delete'), ['token' => $this->field(), 'path' => 'products/someone-elses.jpg'])->assertStatus(422);
        Storage::disk('public')->assertExists('products/someone-elses.jpg');
    }

    public function test_uploads_are_throttled_per_user(): void
    {
        config()->set('kinetix.filesystem.upload_throttle', '2,1');
        $token = $this->field();

        $this->upload($token);
        $this->upload($token);

        $this->postJson(route('kinetix.uploads.store'), [
            'token' => $token,
            'file'  => UploadedFile::fake()->image('third.jpg', 10, 10),
        ])->assertStatus(429)->assertJsonPath('code', 'throttled');

        // The budget is the user's own.
        $this->actingAs(new UploadUser(['id' => 8]));
        $this->upload($this->field());
    }

    public function test_preview_urls_resolve_on_the_fields_disk(): void
    {
        $field = FileUpload::make('photo')->disk('s3');
        $field->withReactiveState(['photo' => 'products/a.jpg']);

        $this->assertSame(['products/a.jpg' => Storage::disk('s3')->url('products/a.jpg')], $field->toData('edit')?->previewUrls);

        // Absolute URLs pass through; no value, no map.
        $field->withReactiveState(['photo' => ['https://cdn.example/x.jpg']]);
        $this->assertSame(['https://cdn.example/x.jpg' => 'https://cdn.example/x.jpg'], $field->toData('edit')?->previewUrls);

        $field->withReactiveState(['photo' => null]);
        $this->assertNull($field->toData('edit')?->previewUrls);
    }

    public function test_a_private_field_signs_only_the_records_file_and_the_users_own_uploads(): void
    {
        $own     = UploadDirectory::for('products', 7).'/mine.jpg';
        $stored  = 'products/'.md5('8').'/stored.jpg';
        $foreign = 'products/'.md5('8').'/not-this-record.jpg';
        $record  = new UploadRecord(['photo' => $stored]);

        $field = FileUpload::make('photo')->directory('products')->disk('s3')->visibility('private')->multiple();
        $field->withReactiveState(['photo' => [$stored, $own, $foreign]]);

        $urls = $field->toData('edit', $record)?->previewUrls;

        $this->assertSame([$stored, $own], array_keys($urls ?? []));
        $this->assertStringContainsString('?expiration=', $urls[$stored]);

        // Nothing previewable: an empty map, so the browser shows a file tile
        // instead of guessing a public URL.
        $field->withReactiveState(['photo' => [$foreign]]);
        $this->assertSame([], $field->toData('edit', $record)?->previewUrls);
    }

    public function test_private_image_columns_reuse_one_temporary_url(): void
    {
        $column = ImageColumn::make('photo')->disk('s3')->visibility('private');
        $record = new UploadRecord(['photo' => 'products/a.jpg']);

        $first = $column->getImageUrl($record);

        $this->assertStringContainsString('?expiration=', (string) $first);
        $this->travel(5)->minutes();
        $this->assertSame($first, $column->getImageUrl($record));

        // The global setting applies when the column doesn't say.
        config()->set('kinetix.filesystem.visibility', 'private');
        $this->assertStringContainsString('?expiration=', (string) ImageColumn::make('photo')->disk('s3')->getImageUrl($record));

        config()->set('kinetix.filesystem.visibility', null);
        $this->assertSame(Storage::disk('s3')->url('products/a.jpg'), ImageColumn::make('photo')->disk('s3')->getImageUrl($record));
    }

    public function test_visibility_only_takes_public_or_private(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileUpload::make('photo')->visibility('privat');
    }
}
