<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tables\Columns\ProgressColumn;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Lists report runs (status/progress/download/cancel/retry) and drives the
 * ad-hoc launch/cancel/retry/download actions.
 */
class ReportRunController
{
    public function __construct(protected ReportRunDispatcher $dispatcher) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $table = Table::make(ReportRun::query()->forCurrentTeam()->latest())
            ->columns([
                TextColumn::make('report_class')
                    ->label((string) __('kinetix.report_runs_report_column'))
                    ->formatStateUsing(fn ($value) => (new $value)->label()),
                TextColumn::make('status')->badge(),
                TextColumn::make('processed_rows')
                    ->label((string) __('kinetix.report_runs_rows_column')),
                ProgressColumn::make('percent')
                    ->color(fn ($value, $record) => $record->status->getColor()),
                TextColumn::make('format'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label((string) __('kinetix.download_export'))
                    ->icon('download')
                    ->iconButton()
                    ->url(fn (ReportRun $run) => route('kinetix.report-runs.download', $run))
                    ->download()
                    ->visible(fn (ReportRun $run) => $run->isDownloadable()),
                Action::make('cancel')
                    ->label((string) __('kinetix.cancel_run'))
                    ->icon('x')
                    ->iconButton()
                    ->color('danger')
                    ->request(fn (ReportRun $run) => route('kinetix.report-runs.cancel', $run))
                    ->requiresConfirmation()
                    ->visible(fn (ReportRun $run) => $run->status->isCancellable()),
                Action::make('retry')
                    ->label((string) __('kinetix.retry_run'))
                    ->icon('rotate-ccw')
                    ->iconButton()
                    ->request(fn (ReportRun $run) => route('kinetix.report-runs.retry', $run))
                    ->visible(fn (ReportRun $run) => $run->status->isRetryable()),
            ]);

        return response()->json($table->toArray());
    }

    public function launch(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        try {
            $report = Report::fromToken((string) $request->input('report', ''));
        } catch (Throwable) {
            return response()->json(['message' => __('kinetix.report_invalid')], 422);
        }

        $run = $this->dispatcher->dispatch(
            report: $report,
            launchedBy: $request->user(),
            parameters: (array) $request->input('parameters', []),
        );

        return response()->json(['status' => 'queued', 'run_id' => $run->id]);
    }

    public function cancel(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $run = $this->findRun($request);

        if (! $run->status->isCancellable()) {
            return response()->json(['message' => __('kinetix.report_run_not_cancellable')], 422);
        }

        $run->forceFill([
            'status'       => ReportRunStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        return response()->json(['status' => 'cancelled']);
    }

    public function retry(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $run = $this->findRun($request);

        if (! $run->status->isRetryable()) {
            return response()->json(['message' => __('kinetix.report_run_not_retryable')], 422);
        }

        if (! class_exists($run->report_class) || ! is_subclass_of($run->report_class, Report::class)) {
            return response()->json(['message' => 'Unknown report class.'], 422);
        }

        /** @var Report $report */
        $report = new $run->report_class;

        $newRun = $this->dispatcher->dispatch(
            report: $report,
            launchedBy: $run->launchedBy,
            parameters: $run->parameters ?? [],
            reportScheduleId: $run->report_schedule_id,
            // The retry stays in the original run's tenant (already verified as
            // the caller's by findRun()).
            teamId: $run->team_id,
        );

        return response()->json(['status' => 'queued', 'run_id' => $newRun->id]);
    }

    public function download(Request $request): StreamedResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $run = $this->findRun($request);

        abort_unless($run->isDownloadable(), 404);

        /** @var string $disk */
        $disk = $run->disk;
        /** @var string $path */
        $path = $run->path;

        return Storage::disk($disk)->download($path, (string) $run->file_name);
    }

    protected function findRun(Request $request): ReportRun
    {
        return ReportRun::query()->forCurrentTeam()->whereKey($request->route('run'))->firstOrFail();
    }
}
