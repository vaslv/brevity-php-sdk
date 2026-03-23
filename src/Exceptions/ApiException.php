<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    /** @var int */
    private $statusCode;

    /** @var array|null */
    private $responseBody;

    public function __construct(string $message, int $statusCode = 0, ?array $responseBody = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
