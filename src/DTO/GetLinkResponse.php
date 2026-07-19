<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

/**
 * Link state returned by `GET /api/v1/links/{code}`: the creation response
 * shape plus a click summary.
 */
class GetLinkResponse extends CreateLinkResponse
{
    /** @var LinkClicks|null */
    private $clicks;

    /**
     * @param  array<string, mixed>|null  $callbackData
     * @param  CreateLinkResponseRule[]  $rules
     */
    public function __construct(
        string $url,
        ?string $domain,
        string $code,
        ?string $title,
        ?bool $forwardQuery,
        ?array $callbackData,
        array $rules,
        ?string $validSince = null,
        ?string $validUntil = null,
        ?int $maxClicks = null,
        ?LinkClicks $clicks = null
    ) {
        parent::__construct(
            $url,
            $domain,
            $code,
            $title,
            $forwardQuery,
            $callbackData,
            $rules,
            $validSince,
            $validUntil,
            $maxClicks
        );
        $this->clicks = $clicks;
    }

    public function getClicks(): ?LinkClicks
    {
        return $this->clicks;
    }

    /**
     * The declared return type must stay invariant with the parent for
     * PHP < 7.4; the actual instance is always a GetLinkResponse.
     *
     * @param  array<string, mixed>  $payload
     * @return GetLinkResponse
     */
    public static function fromArray(array $payload): CreateLinkResponse
    {
        $arguments = self::argumentsFromPayload($payload);
        $arguments[] = isset($payload['clicks']) && is_array($payload['clicks'])
            ? LinkClicks::fromArray($payload['clicks'])
            : null;

        return new self(...$arguments);
    }
}
