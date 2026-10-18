<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use RuntimeException;

/**
 * A provider refused or failed an operation on a line. Its message is meant
 * for the person linking the line ("The bot token is invalid."), so drivers
 * keep provider internals out of it.
 */
class ChannelException extends RuntimeException
{
    /** The field the problem belongs to, for a validation error under it (`token`). */
    public ?string $field = null;

    public static function forField(string $field, string $message): self
    {
        $exception        = new self($message);
        $exception->field = $field;

        return $exception;
    }
}
