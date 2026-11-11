<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Support\ConfigCallback;

/**
 * The human label of a role name, for the screens that list roles (routing
 * audiences, the member roles picker): the host's `permissions.role_label`
 * callback first, then the translated presets the roles seeder creates, else
 * null so the caller shows the name its own way. Translated per request,
 * never at boot.
 */
class RoleLabels
{
    /** The roles the seeder creates, and the translation key of each label. */
    protected const PRESETS = [
        'admin'  => 'role_preset_admin',
        'editor' => 'role_preset_editor',
        'viewer' => 'role_preset_viewer',
    ];

    /**
     * `config('kinetix.permissions.role_label')` is called as
     * `fn (string $name, int|string|null $teamId): ?string`.
     */
    public static function label(string $name, int|string|null $teamId = null): ?string
    {
        $callback = ConfigCallback::resolve(config('kinetix.permissions.role_label'));

        if ($callback !== null) {
            $label = $callback($name, $teamId);

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        $key = self::PRESETS[$name] ?? null;

        return $key === null ? null : (string) __('kinetix::kinetix.'.$key);
    }
}
