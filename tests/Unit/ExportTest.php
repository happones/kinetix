<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class SampleExporter extends Exporter
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')->label('Full Name'),
            ExportColumn::make('email'),
            ExportColumn::make('score')->formatStateUsing(fn ($v) => $v.' pts'),
        ];
    }
}

class ExportTest extends TestCase
{
    public function test_headings_use_labels(): void
    {
        $this->assertSame(['Full Name', 'Email', 'Score'], (new SampleExporter)->headings());
    }

    public function test_csv_writer_writes_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kx').'.csv';

        $writer = new FileWriter($path, 'csv');
        $writer->writeRow(['Full Name', 'Email']);
        $writer->writeRow(['Omar', 'o@x.com']);
        $writer->close();

        $contents = file_get_contents($path);
        // Fields are RFC-4180 quoted only when needed; assert on values, not framing.
        $this->assertStringContainsString('Full Name', $contents);
        $this->assertStringContainsString('Email', $contents);
        $this->assertStringContainsString('Omar', $contents);
        $this->assertStringContainsString('o@x.com', $contents);

        @unlink($path);
    }
}
