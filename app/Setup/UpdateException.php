<?php

namespace App\Setup;

use RuntimeException;

/**
 * Why an update cannot go on. The message is an English translation key of the
 * setup page, and the replacements fill its placeholders. A replacement can be
 * another UpdateException, the cause, translated in turn.
 */
class UpdateException extends RuntimeException
{
    /**
     * @param array<string, string|int|UpdateException> $replace
     */
    public function __construct(string $message, public array $replace = [])
    {
        parent::__construct($message);
    }
}
