<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class CreateLinkRequest
{
    /** @var string|null */
    private $domain;

    /** @var string|null */
    private $title;

    /** @var bool|null */
    private $forwardQuery;

    /** @var array|null */
    private $callbackData;

    /** @var CreateLinkRule[] */
    private $rules;

    /**
     * @param  CreateLinkRule[]  $rules
     */
    public function __construct(
        ?string $domain,
        ?string $title,
        ?bool $forwardQuery,
        ?array $callbackData,
        array $rules
    ) {
        $this->domain = $domain;
        $this->title = $title;
        $this->forwardQuery = $forwardQuery;
        $this->callbackData = $callbackData;
        $this->rules = $rules;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
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
     * @return CreateLinkRule[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'rules' => [],
        ];

        foreach ($this->rules as $rule) {
            $payload['rules'][] = $rule->toArray();
        }

        if ($this->domain !== null) {
            $payload['domain'] = $this->domain;
        }

        if ($this->title !== null) {
            $payload['title'] = $this->title;
        }

        if ($this->forwardQuery !== null) {
            $payload['forward_query'] = $this->forwardQuery;
        }

        if ($this->callbackData !== null) {
            $payload['callback_data'] = $this->callbackData;
        }

        return $payload;
    }
}
