<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where providers call a line: `POST {prefix}/channels/webhook/{public_id}`.
 * Outside the web middleware (no session, no CSRF), so it's authenticated by
 * the line's own secret, which each driver checks in its provider's header.
 */
class ChannelWebhookController
{
    public function __invoke(Request $request, ChannelManager $manager): JsonResponse
    {
        $line = Channel::query()->where('public_id', (string) $request->route('channel'))->first();

        // The same answer for an unknown line and a wrong secret: nothing to
        // learn by probing.
        if ($line === null || ! $manager->has($line->provider) || ! $manager->for($line)->verifyWebhook($line, $request)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $manager->apply($line, $manager->for($line)->parseWebhook($line, $request));

        return response()->json(['status' => 'ok']);
    }
}
