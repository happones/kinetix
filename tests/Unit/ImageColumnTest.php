<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Tables\Columns\ImageColumn;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImageColumnRecord extends Model
{
    protected $table = 'image_records';

    public $timestamps = false;

    protected $guarded = [];
}

class ImageColumnTest extends TestCase
{
    private function record(?string $avatar): ImageColumnRecord
    {
        return new ImageColumnRecord(['avatar' => $avatar]);
    }

    public function test_relative_path_is_resolved_through_the_configured_disk(): void
    {
        Storage::fake('public');
        config()->set('kinetix.filesystem.disk', 'public');

        $url = ImageColumn::make('avatar')->getImageUrl($this->record('avatars/a.png'));

        $this->assertNotSame('avatars/a.png', $url);          // it was resolved
        $this->assertStringContainsString('avatars/a.png', (string) $url);
    }

    public function test_per_column_disk_overrides_the_global_default(): void
    {
        Storage::fake('s3');

        $url = ImageColumn::make('avatar')->disk('s3')->getImageUrl($this->record('p/b.jpg'));

        $this->assertStringContainsString('p/b.jpg', (string) $url);
    }

    public function test_absolute_urls_pass_through_untouched(): void
    {
        Storage::fake('public');

        foreach (['https://cdn.example.com/x.png', '//cdn/x.png', '/storage/x.png', 'data:image/png;base64,AAAA'] as $abs) {
            $this->assertSame(
                $abs,
                ImageColumn::make('avatar')->getImageUrl($this->record($abs)),
            );
        }
    }

    public function test_empty_state_falls_back_to_default_image(): void
    {
        $url = ImageColumn::make('avatar')
            ->defaultImageUrl('https://cdn/placeholder.png')
            ->getImageUrl($this->record(null));

        $this->assertSame('https://cdn/placeholder.png', $url);
    }
}
