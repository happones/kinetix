<?php

declare(strict_types=1);

namespace Happones\Kinetix\Channels;

use RuntimeException;
use Throwable;

/**
 * A provider refused or failed an operation on a line. Its message is meant
 * for the person linking the line ("The bot token is invalid."), so drivers
 * keep provider internals out of it.
 */
class ChannelException extends RuntimeException
{
    /** The field the problem belongs to, for a validation error under it (`token`). */
    public ?string $field = null;

    /**
     * @param bool $refusedByProvider The provider answered and refused (a 4xx), as opposed to Kinetix refusing first.
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly bool $refusedByProvider = false)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function forField(string $field, string $message): self
    {
        $exception        = new self($message);
        $exception->field = $field;

        return $exception;
    }
}
