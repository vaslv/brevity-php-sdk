<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class CreateLinkResponse
{
    /** @var string */
    private $url;

    /** @var string|null */
    private $domain;

    /** @var string */
    private $code;

    /** @var string|null */
    private $title;

    /** @var bool|null */
    private $forwardQuery;

    /** @var array|null */
    private $callbackData;

    /** @var string|null */
    private $validSince;

    /** @var string|null */
    private $validUntil;

    /** @var int|null */
    private $maxClicks;

    /** @var CreateLinkResponseRule[] */
    private $rules;

    /**
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
        ?int $maxClicks = null
    ) {
        $this->url = $url;
        $this->domain = $domain;
        $this->code = $code;
        $this->title = $title;
        $this->forwardQuery = $forwardQuery;
        $this->callbackData = $callbackData;
        $this->rules = $rules;
        $this->validSince = $validSince;
        $this->validUntil = $validUntil;
        $this->maxClicks = $maxClicks;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getForwardQuery(): ?bool
    {
        return $this->forwardQuery;
    }

    public function getCallbackData(): ?array
    {
        return $this->callbackData;
    }

    /**
     * Start of the activity window as sent by the API (ISO 8601), or null.
     */
    public function getValidSince(): ?string
    {
        return $this->validSince;
    }

    /**
     * End of the activity window as sent by the API (ISO 8601), or null.
     */
    public function getValidUntil(): ?string
    {
        return $this->validUntil;
    }

    public function getMaxClicks(): ?int
    {
        return $this->maxClicks;
    }

    /**
     * @return CreateLinkResponseRule[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $rules = [];
        $rawRules = isset($payload['rules']) && is_array($payload['rules']) ? $payload['rules'] : [];
        foreach ($rawRules as $rule) {
            if (is_array($rule)) {
                $rules[] = CreateLinkResponseRule::fromArray($rule);
            }
        }

        return new self(
            (string) $payload['url'],
            isset($payload['domain']) ? (string) $payload['domain'] : null,
            (string) $payload['code'],
            isset($payload['title']) ? (string) $payload['title'] : null,
            isset($payload['forward_query']) ? (bool) $payload['forward_query'] : null,
            isset($payload['callback_data']) && is_array($payload['callback_data']) ? $payload['callback_data'] : null,
            $rules,
            isset($payload['valid_since']) ? (string) $payload['valid_since'] : null,
            isset($payload['valid_until']) ? (string) $payload['valid_until'] : null,
            isset($payload['max_clicks']) ? (int) $payload['max_clicks'] : null
        );
    }
}
