<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

/**
 * Thrown when the API responds with HTTP 429 (rate limit exceeded).
 *
 * Link creation is limited to 120 requests per minute per service. Extends
 * {@see ApiException}, so existing `catch (ApiException)` blocks keep working.
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
        ?int $retryAfter = null
    ) {
        parent::__construct($message, $statusCode, $responseBody);
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
