<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\Gdpr\Events\GdprPurged;
use Happones\Kinetix\Support\Memo;

/**
 * A purged account leaves every group and every rule that named it, in all
 * teams.
 */
class RemovePurgedMember
{
    public function handle(GdprPurged $event): void
    {
        $user = $event->user;
        $key  = $user->getKey();

        if ($key === null) {
            return;
        }

        // Only this model's rows: a Client #5 purged leaves User #5 alone.
        $types = array_values(array_unique([$user->getMorphClass(), $user::class]));

        NotificationGroupMember::query()->where('user_id', $key)->whereIn('user_type', $types)->delete();

        NotificationRoute::query()
            ->where('audience_type', AudienceType::Member->value)
            ->whereIn('audience_id', array_map(static fn (string $type): string => $type.'|'.$key, $types))
            ->delete();

        Memo::flush(AudienceResolver::MEMO);
    }
}
