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
        array $rules
    ) {
        $this->url = $url;
        $this->domain = $domain;
        $this->code = $code;
        $this->title = $title;
        $this->forwardQuery = $forwardQuery;
        $this->callbackData = $callbackData;
        $this->rules = $rules;
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
            $rules
        );
    }
}
