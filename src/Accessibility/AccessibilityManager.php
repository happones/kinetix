<?php

declare(strict_types=1);

namespace Happones\Kinetix\Accessibility;

use Happones\Kinetix\Data\AccessibilityData;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves and persists per-user accessibility preferences, merged over the
 * configured defaults.
 */
class AccessibilityManager
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return (array) config('kinetix.accessibility.defaults', []);
    }

    public function for(Model $user): AccessibilityData
    {
        $stored = AccessibilityPreference::query()
            ->where('user_id', $user->getKey())
            ->value('preferences');

        return AccessibilityData::fromArray(array_merge($this->defaults(), (array) ($stored ?? [])));
    }

    /**
     * Validate + persist a user's preferences, returning the merged result.
     *
     * @param array<string, mixed> $prefs
     */
    public function update(Model $user, array $prefs): AccessibilityData
    {
        // Only known keys are stored; the DTO normalizes values (e.g. textSize).
        $clean = AccessibilityData::fromArray(array_merge($this->defaults(), $prefs))->toArray();

        AccessibilityPreference::updateOrCreate(
            ['user_id' => $user->getKey()],
            ['preferences' => $clean],
        );

        return AccessibilityData::fromArray($clean);
    }
}
