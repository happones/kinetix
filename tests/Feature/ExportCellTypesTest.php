<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Carbon\CarbonImmutable;
use Happones\Kinetix\Exports\CellType;
use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Exports\FileWriter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PayrollExporter extends Exporter
{
    protected static ?string $model = Model::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name'),
            ExportColumn::make('birthday')->date('m/d/yyyy'),
            ExportColumn::make('postal_code')->text(),
            ExportColumn::make('daily_salary')->numeric('0.00'),
            ExportColumn::make('notes'),
        ];
    }
}

/**
 * Typed export cells: real dates and numbers with their number format, text
 * that keeps its leading zeros — and the xls (Excel 97) format.
 */
class ExportCellTypesTest extends TestCase
{
    /** @var array<int, string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function write(string $format, array $rows): string
    {
        $exporter = new PayrollExporter;
        $path     = (string) tempnam(sys_get_temp_dir(), 'kinetix_types_');

        $this->paths[] = $path;

        $writer = (new FileWriter($path, $format))->withCellTypes($exporter->cellTypes());
        $writer->writeRow($exporter->headings());

        foreach ($rows as $row) {
            $writer->writeRow($row);
        }

        $writer->close();

        return $path;
    }

    private function sheet(string $path): Worksheet
    {
        return IOFactory::load($path)->getActiveSheet();
    }

    public function test_columns_declare_their_cell_types(): void
    {
        $this->assertEquals([
            null,
            ['type' => CellType::Date, 'format' => 'm/d/yyyy'],
            ['type' => CellType::Text, 'format' => null],
            ['type' => CellType::Number, 'format' => '0.00'],
            null,
        ], (new PayrollExporter)->cellTypes());
    }

    public function test_xlsx_cells_are_real_dates_numbers_and_text(): void
    {
        $sheet = $this->sheet($this->write('xlsx', [
            ['Ana', CarbonImmutable::parse('2001-04-12'), '06700', '512.5', '42'],
        ]));

        $this->assertSame('Birthday', $sheet->getCell('B1')->getValue(), 'The header row is never typed');

        $birthday = $sheet->getCell('B2');
        $this->assertSame(DataType::TYPE_NUMERIC, $birthday->getDataType());
        $this->assertSame('2001-04-12', ExcelDate::excelToDateTimeObject((float) $birthday->getValue())->format('Y-m-d'));
        $this->assertSame('m/d/yyyy', $birthday->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('4/12/2001', $birthday->getFormattedValue());

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
        $this->assertSame('06700', $sheet->getCell('C2')->getValue());

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
        $this->assertSame('512.50', $sheet->getCell('D2')->getFormattedValue());

        // An untyped column still lets the spreadsheet guess.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E2')->getDataType());
    }

    public function test_xls_is_written_with_the_same_types(): void
    {
        $path = $this->write('xls', [['Ana', '2001-04-12', '06700', 512.5, null]]);

        $this->assertSame('Xls', IOFactory::identify($path));

        $sheet = $this->sheet($path);
        $this->assertSame('4/12/2001', $sheet->getCell('B2')->getFormattedValue());
        $this->assertSame('06700', $sheet->getCell('C2')->getValue());
        $this->assertSame('512.50', $sheet->getCell('D2')->getFormattedValue());
    }

    public function test_csv_holds_the_text_each_format_shows(): void
    {
        $csv = (string) file_get_contents($this->write('csv', [
            ['Ana', CarbonImmutable::parse('2001-04-12 10:30'), 6700, 512.5, 'x'],
        ]));

        $this->assertStringContainsString('Ana,4/12/2001,6700,512.50,x', $csv);
    }

    public function test_a_value_that_cannot_be_its_type_is_kept_as_written(): void
    {
        $sheet = $this->sheet($this->write('xlsx', [
            ['Ana', 'not a date', null, 'n/a', null],
            ['Total', '', '', 'Sum: 10', ''],
        ]));

        $this->assertSame('not a date', $sheet->getCell('B2')->getValue());
        $this->assertSame('n/a', $sheet->getCell('D2')->getValue());
        $this->assertNull($sheet->getCell('C2')->getValue());
        $this->assertSame('Sum: 10', $sheet->getCell('D3')->getValue());
    }

    public function test_a_typed_text_cell_still_defuses_formulas(): void
    {
        $sheet = $this->sheet($this->write('xlsx', [['Ana', null, '=1+1', null, null]]));

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
        $this->assertSame("\t=1+1", $sheet->getCell('C2')->getValue());
    }
}
