<?php

namespace KilnWeb;

/** The licence server answered {ok:false}. getCode() is the HTTP status. */
final class LicenseApiError extends \RuntimeException
{
    public function __construct(string $message, int $status, public readonly string $errorCode)
    {
        parent::__construct($message, $status);
    }
}
