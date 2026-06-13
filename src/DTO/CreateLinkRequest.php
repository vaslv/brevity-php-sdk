<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

use Vaslv\Brevity\Exceptions\InvalidRequestException;

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

    /** @var string|null */
    private $domainStrategy;

    /** @var string|null */
    private $domainGroup;

    /**
     * Pick the domain in exactly one way: either an explicit $domain, or a
     * $domainStrategy (with an optional $domainGroup), or neither (the server
     * falls back to the default domain). Contradictory combinations are rejected
     * up front with an {@see InvalidRequestException}.
     *
     * @param  CreateLinkRule[]  $rules
     * @param  string|null  $domainStrategy  Domain auto-pick strategy: `random` / `round_robin` / `coldest`.
     *                                       Mutually exclusive with $domain; required when $domainGroup is set.
     * @param  string|null  $domainGroup  Group code restricting the auto-pick pool. Requires $domainStrategy.
     *
     * @throws InvalidRequestException $domain and $domainStrategy together, or $domainGroup without $domainStrategy.
     */
    public function __construct(
        ?string $domain,
        ?string $title,
        ?bool $forwardQuery,
        ?array $callbackData,
        array $rules,
        ?string $domainStrategy = null,
        ?string $domainGroup = null
    ) {
        $this->domain = $domain;
        $this->title = $title;
        $this->forwardQuery = $forwardQuery;
        $this->callbackData = $callbackData;
        $this->rules = $rules;
        $this->domainStrategy = $domainStrategy;
        $this->domainGroup = $domainGroup;

        $this->assertValidDomainOptions();
    }

    /**
     * Enforce the domain-selection contract (§8): a concrete domain and a
     * strategy are mutually exclusive, and a group only makes sense with a
     * strategy to pick from it.
     *
     * @throws InvalidRequestException
     */
    private function assertValidDomainOptions(): void
    {
        if ($this->domain !== null && $this->domainStrategy !== null) {
            throw new InvalidRequestException(
                'Pass either an explicit `domain` or a `domainStrategy`, not both.'
            );
        }

        if ($this->domainGroup !== null && $this->domainStrategy === null) {
            throw new InvalidRequestException(
                'A `domainGroup` requires a `domainStrategy` to select a domain from it.'
            );
        }
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

    public function getDomainStrategy(): ?string
    {
        return $this->domainStrategy;
    }

    public function getDomainGroup(): ?string
    {
        return $this->domainGroup;
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

        if ($this->domainStrategy !== null) {
            $payload['domain_strategy'] = $this->domainStrategy;
        }

        if ($this->domainGroup !== null) {
            $payload['domain_group'] = $this->domainGroup;
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
