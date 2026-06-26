<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Tables\Columns\Summarizers\Sum;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExportSummaryOrder extends Model
{
    protected $table = 'export_summary_orders';

    public $timestamps = false;

    protected $guarded = [];
}

class SummedExporter extends Exporter
{
    protected static ?string $model = ExportSummaryOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference'),
            ExportColumn::make('total')->summarize(Sum::make()->label('Total')),
        ];
    }
}

class PlainExporter extends Exporter
{
    protected static ?string $model = ExportSummaryOrder::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('reference')];
    }
}

class ExportSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('export_summary_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reference');
            $table->integer('total');
        });

        ExportSummaryOrder::insert([
            ['reference' => 'INV-1', 'total' => 150],
            ['reference' => 'INV-2', 'total' => 250],
        ]);
    }

    public function test_exporter_reports_having_a_summary(): void
    {
        $this->assertTrue((new SummedExporter)->hasSummary());
        $this->assertFalse((new PlainExporter)->hasSummary());
    }

    public function test_summary_row_aligns_values_and_totals_label(): void
    {
        $exporter = new SummedExporter;

        $row = $exporter->summaryRow($exporter->resolveExportQuery());

        $this->assertNotNull($row);
        // First (summary-less) column carries the "Total" label; second is the sum.
        $this->assertSame(trans('kinetix.summary_total'), $row[0]);
        $this->assertSame('Total: 400', $row[1]);
    }

    public function test_plain_exporter_has_no_summary_row(): void
    {
        $exporter = new PlainExporter;

        $this->assertNull($exporter->summaryRow($exporter->resolveExportQuery()));
    }

    public function test_with_summary_can_be_disabled(): void
    {
        $exporter = new class extends SummedExporter
        {
            protected bool $withSummary = false;
        };

        $this->assertFalse($exporter->hasSummary());
        $this->assertNull($exporter->summaryRow($exporter->resolveExportQuery()));
    }
}
