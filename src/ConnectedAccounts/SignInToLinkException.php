<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use RuntimeException;

/**
 * Thrown by the provider login when its email already belongs to a user who
 * never linked that provider, and `connected_accounts.link_by_email` does not
 * allow linking on the email alone. The person signs in first, then connects
 * the provider from their profile.
 */
class SignInToLinkException extends RuntimeException {}
