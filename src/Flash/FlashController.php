<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flash;

use Illuminate\Http\JsonResponse;

/**
 * Closes a session alert (`->keep()` / `->untilDismissed()`). It only ever
 * touches the requester's own session, so it needs the session and CSRF
 * protection of `web` but no login — alerts work on guest pages too.
 */
class FlashController
{
    public function dismiss(string $id): JsonResponse
    {
        KinetixFlash::dismiss($id);

        return response()->json(['dismissed' => true]);
    }
}
