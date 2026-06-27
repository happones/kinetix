<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use RuntimeException;

/**
 * Thrown when an OAuth identity is already linked to a *different* Kinetix user,
 * so it cannot be re-linked to the current one.
 */
class AccountAlreadyLinkedException extends RuntimeException {}
