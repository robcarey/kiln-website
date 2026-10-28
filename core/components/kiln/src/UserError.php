<?php

namespace KilnWeb;

/** A problem worth telling the visitor about, in words they can act on. */
final class UserError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
