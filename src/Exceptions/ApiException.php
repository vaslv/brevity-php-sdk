<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

use RuntimeException;

/**
 * Base exception for API error responses (RFC 7807 `problem+json` under `/api/v1`).
 *
 * Carries the HTTP status, the decoded problem body and the stable machine
 * code from the problem `type` field. Subclasses cover the well-known types;
 * this class itself is thrown for `http-error`, `server-error` and any type
 * the SDK does not know yet.
 */
class ApiException extends RuntimeException
{
    /** @var int */
    private $statusCode;

    /** @var array|null */
    private $responseBody;

    /** @var string|null */
    private $problemType;

    public function __construct(string $message, int $statusCode = 0, ?array $responseBody = null, ?string $problemType = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->problemType = $problemType;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    /**
     * Stable machine code from the RFC 7807 `type` field, or null when the
     * response carried none (e.g. a proxy answering instead of the API).
     */
    public function getProblemType(): ?string
    {
        return $this->problemType;
    }
}
