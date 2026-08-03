<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\ReportScheduleData;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * CRUD + listing for recurring/scheduled report definitions.
 */
class ReportScheduleController
{
    public function __construct(protected ReportRunDispatcher $dispatcher) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $table = Table::make(ReportSchedule::query()->forCurrentTeam()->latest())
            ->columns([
                TextColumn::make('report_class')
                    ->label((string) trans('kinetix.report_runs_report_column'))
                    ->formatStateUsing(fn ($value) => (new $value)->label()),
                TextColumn::make('frequency'),
                ToggleColumn::make('enabled'),
                TextColumn::make('next_run_at')->dateTime(),
                TextColumn::make('last_run_at')->dateTime(),
            ])
            ->recordActions([
                Action::make('run-now')
                    ->label((string) trans('kinetix.run_now'))
                    ->icon('play')
                    ->iconButton()
                    ->request(fn (ReportSchedule $schedule) => route('kinetix.report-schedules.run-now', $schedule)),
                Action::make('delete')
                    ->label((string) trans('kinetix.delete'))
                    ->icon('trash')
                    ->iconButton()
                    ->color('danger')
                    ->request(fn (ReportSchedule $schedule) => route('kinetix.report-schedules.destroy', $schedule), ['method' => 'delete'])
                    ->requiresConfirmation(),
            ]);

        return response()->json($table->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $data = $this->validated($request);

        try {
            Report::fromToken($data['report']);
        } catch (Throwable) {
            return response()->json(['message' => 'Invalid report.'], 422);
        }

        $reportClass = $this->classFromToken($data['report']);

        $schedule = ReportSchedule::create([
            ...ReportSchedule::teamAttributes(),
            'report_class'         => $reportClass,
            'frequency'            => $data['frequency'],
            'parameters'           => $data['parameters']           ?? [],
            'enabled'              => $data['enabled']              ?? true,
            'notify_on_completion' => $data['notify_on_completion'] ?? false,
            'next_run_at'          => now(),
            'created_by_id'        => $request->user()?->getKey(),
        ]);

        return response()->json(ReportScheduleData::fromModel($schedule));
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $schedule = $this->findSchedule($request);

        $data = $request->validate([
            'frequency'            => ['required', 'string'],
            'parameters'           => ['nullable', 'array'],
            'enabled'              => ['boolean'],
            'notify_on_completion' => ['boolean'],
        ]);

        $schedule->update($data);

        return response()->json(ReportScheduleData::fromModel($schedule->fresh()));
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $this->findSchedule($request)->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function runNow(Request $request): JsonResponse
    {
        Gate::authorize('viewKinetixReportsCenter');

        $schedule = $this->findSchedule($request);

        $run = $this->dispatcher->dispatch(
            report: $schedule->report(),
            launchedBy: $schedule->createdBy,
            parameters: $schedule->parameters ?? [],
            reportScheduleId: $schedule->id,
        );

        return response()->json(['status' => 'queued', 'run_id' => $run->id]);
    }

    protected function findSchedule(Request $request): ReportSchedule
    {
        return ReportSchedule::query()->forCurrentTeam()->whereKey($request->route('schedule'))->firstOrFail();
    }

    /**
     * @return array{report: string, frequency: string, parameters?: array<string, mixed>, enabled?: bool, notify_on_completion?: bool}
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'report'               => ['required', 'string'],
            'frequency'            => ['required', 'string'],
            'parameters'           => ['nullable', 'array'],
            'enabled'              => ['boolean'],
            'notify_on_completion' => ['boolean'],
        ]);
    }

    /**
     * @return class-string<Report>
     */
    protected function classFromToken(string $token): string
    {
        return Report::fromToken($token)::class;
    }
}
