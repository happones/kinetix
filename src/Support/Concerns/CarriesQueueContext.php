<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Illuminate\Http\Request;

/**
 * Request context carried into a queued job. The worker has no request, so
 * anything tenant-scoped — the current team, the acting user — is captured by
 * context() at dispatch, serialized with the job and restored on the worker's
 * instance before it does any work; read it back via `$this->context` or
 * getContext().
 *
 *     public function context(Request $request): array
 *     {
 *         return ['team_id' => $request->user()?->currentTeam?->getKey()];
 *     }
 *
 * Without it, a queued importer or exporter that infers the tenant
 * (`Team::first()`, an unscoped query) reads or writes another tenant's rows.
 */
trait CarriesQueueContext
{
    /**
     * Request-captured context restored on the queued worker (see context()).
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * Capture request context to carry into the queued job.
     *
     * @return array<string, mixed> serializable values only
     */
    public function context(Request $request): array
    {
        return [];
    }

    /**
     * Restore captured context on this instance (called by the queued job).
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * The context captured at dispatch time (empty when dispatched without one).
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
