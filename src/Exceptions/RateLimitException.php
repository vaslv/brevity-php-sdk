<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

/**
 * Thrown when the API responds with HTTP 429 `too-many-requests`.
 *
 * Requests are limited to 120 per minute per service, with independent
 * budgets for writes (`POST`/`PATCH` links) and reads (`GET` link and the
 * registries). Extends {@see ApiException}, so existing
 * `catch (ApiException)` blocks keep working.
 */
class RateLimitException extends ApiException
{
    /** @var int|null Seconds to wait before retrying, parsed from the `Retry-After` header (null if absent). */
    private $retryAfter;

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        string $message = 'Rate limit exceeded.',
        int $statusCode = 429,
        ?array $responseBody = null,
        ?int $retryAfter = null,
        ?string $problemType = null
    ) {
        parent::__construct($message, $statusCode, $responseBody, $problemType);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Seconds to wait before retrying, if the server sent a `Retry-After` header.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
