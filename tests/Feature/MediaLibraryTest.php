<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\MediaLibrary;
use Happones\Kinetix\Media\KinetixMedia;
use Happones\Kinetix\Media\MediaManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

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
}
