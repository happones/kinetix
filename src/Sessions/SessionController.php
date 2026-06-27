<?php

declare(strict_types=1);

namespace Happones\Kinetix\Sessions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service browser sessions: each user lists their own active sessions and
 * can log out every other device. Requires SESSION_DRIVER=database for the list;
 * logging out others is password-gated when the user has a password.
 */
class SessionController
{
    public function __construct(protected BrowserSessionManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'sessions'         => $this->manager->for($user, $request),
            'databaseDriver'   => $this->manager->usesDatabaseDriver(),
            'requiresPassword' => $this->requiresPassword($user),
        ]);
    }

    /**
     * Log out the user's other browser sessions.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($this->requiresPassword($user)) {
            $request->validate(['password' => ['required', 'current_password']]);
        }

        $count = $this->manager->logoutOthers($user, $request);

        return response()->json(['status' => 'success', 'count' => $count]);
    }

    protected function user(Request $request): Model
    {
        $user = $request->user();

        abort_if(! $user instanceof Model, 401);

        return $user;
    }

    /**
     * Password confirmation is required only when enabled and the user actually
     * has a password (social-only users have none to confirm).
     */
    protected function requiresPassword(Model $user): bool
    {
        if (! config('kinetix.sessions.require_password', true)) {
            return false;
        }

        $password = $user instanceof Authenticatable
            ? $user->getAuthPassword()
            : $user->getAttribute('password');

        return filled($password);
    }
}
