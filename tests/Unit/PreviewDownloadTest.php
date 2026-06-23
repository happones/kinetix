<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\DownloadAction;
use Happones\Kinetix\Actions\PreviewAction;
use Happones\Kinetix\Tables\Columns\ImageColumn;
use Happones\Kinetix\Tests\TestCase;

class PreviewDownloadTest extends TestCase
{
    public function test_download_action_flags_download(): void
    {
        $data = DownloadAction::make()
            ->url('/files/1/download')
            ->toData();

        $this->assertNotNull($data);
        $this->assertTrue($data->isDownload);
        $this->assertFalse($data->isPreview);
        $this->assertSame('download', $data->icon);
    }

    public function test_preview_action_flags_preview_with_type(): void
    {
        $data = PreviewAction::make()
            ->url('/files/1/preview')
            ->preview('pdf')
            ->toData();

        $this->assertNotNull($data);
        $this->assertTrue($data->isPreview);
        $this->assertSame('pdf', $data->previewType);
    }

    public function test_generic_action_download_and_preview_builders(): void
    {
        $download = Action::make('dl')->url('/x')->download()->toData();
        $this->assertTrue($download->isDownload);

        $preview = Action::make('pv')->url('/x')->preview()->toData();
        $this->assertTrue($preview->isPreview);
        $this->assertSame('auto', $preview->previewType);
    }

    public function test_image_column_preview_flag_serializes(): void
    {
        $this->assertFalse(ImageColumn::make('avatar')->toData()->isPreviewable);
        $this->assertTrue(ImageColumn::make('avatar')->preview()->toData()->isPreviewable);
    }
}
