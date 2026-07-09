<?php

declare(strict_types=1);

namespace Happones\Kinetix\Api\Middleware;

use Closure;
use Happones\Kinetix\Api\ApiLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Logs API requests to `kinetix_api_logs` — apply the `kinetix.api-log` alias
 * to your API routes/group. The row is written in terminate() (after the
 * response is sent) so logging adds no request latency; bodies are captured
 * only when enabled and always truncated. Sensitive request fields are
 * redacted by key.
 *
 *     Route::middleware(['auth:sanctum', 'kinetix.api-log'])->prefix('api/v1')->group(…);
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        // Stored on the request: Laravel resolves a FRESH middleware instance
        // for terminate(), so instance state would not survive.
        $request->attributes->set('kinetix_api_log_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('kinetix.api_logs.enabled', false)) {
            return;
        }

        try {
            ApiLog::query()->create([
                'user_id'     => $request->user()?->getAuthIdentifier(),
                'token_id'    => $this->tokenId($request),
                'token_name'  => $this->tokenName($request),
                'method'      => $request->getMethod(),
                'path'        => '/'.ltrim($request->path(), '/'),
                'status'      => $response->getStatusCode(),
                'duration_ms' => is_float($startedAt = $request->attributes->get('kinetix_api_log_started_at'))
                    ? (int) round((microtime(true) - $startedAt) * 1000)
                    : null,
                'ip'            => $request->ip(),
                'request_body'  => $this->requestBody($request),
                'response_body' => $this->responseBody($response),
                'created_at'    => now(),
            ]);
        } catch (Throwable) {
            // Logging must never break the API response path.
        }
    }

    protected function tokenId(Request $request): int|string|null
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        return $user->currentAccessToken()?->getKey();
    }

    protected function tokenName(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();

        return $token !== null ? ($token->name ?? null) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function requestBody(Request $request): ?array
    {
        if (! config('kinetix.api_logs.log_request_body', false)) {
            return null;
        }

        $redact = array_map(strtolower(...), (array) config('kinetix.api_logs.redact', [
            'password', 'password_confirmation', 'secret', 'token', 'authorization',
        ]));

        $body = collect($request->except(['_token']))
            ->map(fn ($value, string $key) => in_array(strtolower($key), $redact, true) ? '[redacted]' : $value)
            ->all();

        if ($body === []) {
            return null;
        }

        // Cap the stored size — huge payloads become a marker instead.
        $limit = (int) config('kinetix.api_logs.body_limit', 10240);

        if (strlen((string) json_encode($body)) > $limit) {
            return ['_truncated' => true];
        }

        return $body;
    }

    protected function responseBody(Response $response): ?string
    {
        if (! config('kinetix.api_logs.log_response_body', false)) {
            return null;
        }

        $content = (string) $response->getContent();

        if ($content === '') {
            return null;
        }

        $limit = (int) config('kinetix.api_logs.body_limit', 10240);

        return strlen($content) > $limit ? substr($content, 0, $limit).'…' : $content;
    }
}
