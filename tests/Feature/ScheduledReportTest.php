<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Exports\ExportColumn;
use Happones\Kinetix\Exports\Exporter;
use Happones\Kinetix\Reports\KinetixReports;
use Happones\Kinetix\Reports\ReportRegistry;
use Happones\Kinetix\Reports\ReportRunner;
use Happones\Kinetix\Reports\ScheduledReport;
use Happones\Kinetix\Reports\ScheduledReportMail;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class ReportOrder extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];
}

class OrdersReportExporter extends Exporter
{
    protected static ?string $model = ReportOrder::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('total'),
        ];
    }
}

class ScheduledReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('total')->default(0);
        });
    }

    public function test_registry_filters_by_frequency_and_enabled(): void
    {
        $registry = new ReportRegistry;
        $registry->register(ScheduledReport::make('a')->frequency('daily'));
        $registry->register(ScheduledReport::make('b')->frequency('weekly'));
        $registry->register(ScheduledReport::make('c')->frequency('daily')->enabled(false));

        $this->assertCount(3, $registry->all());
        $this->assertCount(1, $registry->due('daily'));   // a only (c disabled)
        $this->assertSame('a', $registry->due('daily')[0]->getKey());
        $this->assertCount(2, $registry->due());          // a + b (enabled, any frequency)
    }

    public function test_report_definition_defaults(): void
    {
        $report = ScheduledReport::make('daily-orders')
            ->exporter(OrdersReportExporter::class)
            ->to('ops@acme.com');

        $this->assertSame('daily', $report->getFrequency());
        $this->assertSame(['ops@acme.com'], $report->getRecipients());
        $this->assertSame('Daily Orders', $report->getSubject()); // headline of key
        $this->assertSame(OrdersReportExporter::class, $report->getExporter());
    }

    public function test_runner_emails_the_report_with_an_attachment(): void
    {
        Mail::fake();
        ReportOrder::create(['total' => 10]);

        $report = ScheduledReport::make('orders')
            ->exporter(OrdersReportExporter::class)
            ->to(['ops@acme.com', 'cfo@acme.com'])
            ->subject('Orders');

        $this->assertTrue(app(ReportRunner::class)->run($report));

        Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            return $mail->hasTo('ops@acme.com')
                && $mail->hasTo('cfo@acme.com')
                && $mail->reportSubject === 'Orders'
                && str_ends_with($mail->attachmentName, '.csv');
        });
    }

    public function test_runner_skips_when_no_recipients(): void
    {
        Mail::fake();

        $report = ScheduledReport::make('orphan')->exporter(OrdersReportExporter::class);

        $this->assertFalse(app(ReportRunner::class)->run($report));
        Mail::assertNothingSent();
    }

    public function test_command_sends_due_reports(): void
    {
        Mail::fake();
        KinetixReports::register(
            ScheduledReport::make('daily-orders')
                ->exporter(OrdersReportExporter::class)
                ->frequency('daily')
                ->to('ops@acme.com'),
        );

        $this->artisan('kinetix:reports:send', ['--frequency' => 'daily'])
            ->assertSuccessful();

        Mail::assertSent(ScheduledReportMail::class, 1);
    }

    public function test_command_fails_on_unknown_report(): void
    {
        $this->artisan('kinetix:reports:send', ['report' => 'nope'])
            ->assertFailed();
    }
}
