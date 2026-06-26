<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

/**
 * Record action for a user row: "log in as" that user. Authorized by the
 * `users.impersonate` ability and posts to the Kinetix impersonation endpoint
 * (an Inertia visit, so the page reloads as the impersonated user).
 */
class ImpersonateAction extends Action
{
    public static function make(string $name = 'impersonate'): static
    {
        return new static($name);
    }

    public function __construct(string $name = 'impersonate')
    {
        parent::__construct($name);

        $this->label((string) trans('kinetix.impersonate'))
            ->icon('user')
            ->color('gray')
            ->authorize('users.impersonate')
            ->inertiaVisit(
                fn ($record) => route('kinetix.impersonation.start', $record),
                ['method' => 'post'],
            );
    }
}
