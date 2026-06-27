<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Tests\TestCase;

class PdfExportTest extends TestCase
{
    public function test_writes_a_real_pdf_from_buffered_rows(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kinetix_pdf_').'.pdf';

        $writer = new FileWriter($path, 'pdf');
        $writer->writeRow(['Name', 'Email', 'Role']);
        $writer->writeRow(['Ada Lovelace', 'ada@example.com', 'Admin']);
        $writer->writeRow(['Grace Hopper', 'grace@example.com', 'Editor']);
        $writer->close();

        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        // A valid PDF starts with the %PDF- magic header.
        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertGreaterThan(500, strlen($contents));

        @unlink($path);
    }
}
