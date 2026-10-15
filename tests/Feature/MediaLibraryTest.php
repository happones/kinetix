<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\MediaLibrary;
use Happones\Kinetix\Forms\UploadDirectory;
use Happones\Kinetix\Media\KinetixMedia;
use Happones\Kinetix\Media\MediaManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class MediaProduct extends Model
{
    protected $table = 'products';

    public $timestamps = false;

    protected $guarded = [];
}

class MediaLibraryTest extends TestCase
{
    public function test_it_serializes_as_a_media_library_field(): void
    {
        $data = MediaLibrary::make('gallery')
            ->collection('images')
            ->conversions(['thumb'])
            ->image()
            ->maxFiles(5)
            ->toData('create');

        $this->assertSame('media-library', $data->type);
        $this->assertTrue($data->isMultiple);     // multiple by default
        $this->assertTrue($data->isReorderable);  // reorderable by default
        $this->assertTrue($data->isImage);
        $this->assertSame(5, $data->maxFiles);
        $this->assertSame('images', $data->mediaCollection);
        $this->assertSame(['thumb'], $data->mediaConversions);

        // The signed upload token (inherited from FileUpload) is present and
        // carries the disk/directory config.
        $this->assertNotNull($data->uploadToken);
        $this->assertArrayHasKey('directory', Crypt::decrypt($data->uploadToken));
    }

    public function test_reorderable_can_be_disabled(): void
    {
        $this->assertFalse(MediaLibrary::make('g')->reorderable(false)->toData('create')->isReorderable);
    }

    public function test_manager_is_a_noop_without_spatie(): void
    {
        $manager = app(MediaManager::class);
        $record  = new MediaProduct(['id' => 1]);

        $this->assertFalse($manager->usesSpatie($record));
        $this->assertSame([], $manager->items($record, 'images'));

        // sync must not throw when spatie isn't available.
        $manager->sync($record, 'images', [['path' => 'uploads/x.jpg']]);
        $this->assertTrue(true);
    }

    public function test_facade_delegates_to_the_manager(): void
    {
        $this->assertSame([], KinetixMedia::items(new MediaProduct(['id' => 1]), 'images'));
    }

    public function test_keep_accepts_only_the_users_own_uploads_and_rebuilds_them_from_the_disk(): void
    {
        Storage::fake('public');
        config()->set('kinetix.filesystem.scope_uploads_by_user', true);
        $this->actingAs(new MediaUser(['id' => 7]));

        $mine    = 'products/'.md5('7').'/a1B2c3.jpg';
        $plain   = 'products/'.md5('7').'/d4E5f6.jpg';
        $theirs  = 'products/'.md5('8').'/secret.pdf';
        $outside = 'exports/report.csv';
        $stored  = 'products/'.md5('8').'/kept.jpg';

        foreach ([$mine, $plain, $theirs, $outside] as $path) {
            Storage::disk('public')->put($path, 'x');
        }

        $kept = KinetixMedia::keep(
            [
                ['path' => $stored, 'url' => 'https://evil.example/forged'],
                ['path' => $mine, 'url' => 'javascript:alert(1)', 'name' => "../photos/My\nmug.jpg"],
                ['path' => $mine, 'name' => 'duplicate.jpg'],
                ['path' => $plain],
                ['path' => $theirs],
                ['path' => $outside],
                ['path' => 'products/'.md5('7').'/../../exports/report.csv'],
                ['path' => 'products/'.md5('7').'/missing.jpg'],
            ],
            current: [['path' => $stored, 'url' => '/storage/kept.jpg', 'name' => 'kept.jpg']],
            directory: 'products',
        );

        $this->assertSame([$stored, $mine, $plain], array_column($kept, 'path'));
        $this->assertSame('/storage/kept.jpg', $kept[0]['url']);
        $this->assertSame(Storage::disk('public')->url($mine), $kept[1]['url']);
        // The name is only a label: the uploaded file's original name, as a
        // plain single-line file name; the stored name when none was sent.
        $this->assertSame('Mymug.jpg', $kept[1]['name']);
        $this->assertSame('d4E5f6.jpg', $kept[2]['name']);
    }

    public function test_an_upload_directory_is_the_users_own_unless_scoping_is_off(): void
    {
        config()->set('kinetix.filesystem.scope_uploads_by_user', true);
        $this->assertSame('products/'.md5('7'), UploadDirectory::for('/products/', 7));
        $this->assertSame('uploads/'.md5('7'), UploadDirectory::for(null, 7));

        config()->set('kinetix.filesystem.scope_uploads_by_user', false);
        $this->assertSame('products', UploadDirectory::for('products', 7));
    }

    public function test_the_facade_hands_sync_the_fields_directory(): void
    {
        $manager = $this->mock(MediaManager::class);
        $record  = new MediaProduct(['id' => 1]);

        $manager->shouldReceive('sync')->once()->with($record, 'images', [], 'public', 'products');

        KinetixMedia::sync($record, 'images', [], 'public', 'products');
    }
}

class MediaUser extends User
{
    protected $guarded = [];
}
