<?php

namespace ingeniousai\domainguard\core;

/**
 * A DomainGuard gateway failure with a stable machine code and a
 * user-readable message. Mirrors the WP_Error contract of the WordPress
 * plugin's DGFS_API so both plugins behave identically at the edges.
 */
class ApiException extends \Exception
{
    /** @var string Stable machine code, e.g. 'unauthorized', 'no_key'. */
    public string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

/**
 * The HTTP transport itself failed (DNS, TLS, timeout) before the
 * gateway could answer.
 */
class TransportException extends \Exception
{
}
