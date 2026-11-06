<?php

declare(strict_types=1);

namespace Happones\Kinetix\Api\Middleware;

use Closure;
use Happones\Kinetix\Api\ApiLog;
use Happones\Kinetix\Support\SensitiveKeys;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Logs API requests to `kinetix_api_logs` — apply the `kinetix.api-log` alias
 * to your API routes/group. The row is written in terminate() (after the
 * response is sent) so logging adds no request latency; bodies are captured
 * only when enabled and always truncated. Secret-looking keys are redacted
 * at any depth of the request body and of a JSON response body
 * ({@see SensitiveKeys}); other response bodies are stored as they are.
 *
 *     Route::middleware(['auth:sanctum', 'kinetix.api-log'])->prefix('api/v1')->group(…);
 */
class LogApiRequest
{
    /** A JSON response larger than this is not decoded to redact it. */
    private const MAX_REDACTABLE_BYTES = 1_048_576;

    /** What such a response is stored as: no raw prefix is kept. */
    private const TOO_LARGE = '[omitted: too large to redact]';

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
                // An API route rarely carries a `{current_team}` segment, so
                // this usually resolves through the token holder's currentTeam.
                ...ApiLog::teamAttributes(),
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
     * The request body with every secret-looking key redacted, at any depth
     * ({@see SensitiveKeys}), before the size cap.
     *
     * @return array<string, mixed>|null
     */
    protected function requestBody(Request $request): ?array
    {
        if (! config('kinetix.api_logs.log_request_body', false)) {
            return null;
        }

        $body = SensitiveKeys::redact($request->except(['_token']), $this->extraRedactions());

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

    /**
     * The response body: a JSON one with its secret-looking keys redacted
     * BEFORE it is cut to the size cap, so the cut never exposes a secret
     * the redaction would have hidden. A JSON body too large to decode is
     * stored as a marker; any other body as it is (truncated).
     */
    protected function responseBody(Response $response): ?string
    {
        if (! config('kinetix.api_logs.log_response_body', false)) {
            return null;
        }

        $content = (string) $response->getContent();

        if ($content === '') {
            return null;
        }

        if ($this->looksLikeJson($response, $content)) {
            if (strlen($content) > self::MAX_REDACTABLE_BYTES) {
                return self::TOO_LARGE;
            }

            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                $content = (string) json_encode(
                    SensitiveKeys::redact($decoded, $this->extraRedactions()),
                    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE,
                );
            }
        }

        $limit = (int) config('kinetix.api_logs.body_limit', 10240);

        return strlen($content) > $limit ? substr($content, 0, $limit).'…' : $content;
    }

    /** Whether the response is JSON: by its Content-Type, or by its first character. */
    private function looksLikeJson(Response $response, string $content): bool
    {
        if (str_contains(strtolower((string) $response->headers->get('Content-Type', '')), 'json')) {
            return true;
        }

        $first = ltrim($content)[0] ?? '';

        return $first === '{' || $first === '[';
    }

    /**
     * The host's `api_logs.redact` additions, lower-cased.
     *
     * @return list<string>
     */
    private function extraRedactions(): array
    {
        return array_values(array_map(
            static fn (mixed $key): string => strtolower((string) $key),
            array_filter((array) config('kinetix.api_logs.redact', []), is_scalar(...)),
        ));
    }
}
