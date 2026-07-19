<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

use Vaslv\Brevity\Exceptions\InvalidRequestException;

class CreateLinkRequest
{
    /** Serialization format for `valid_since` / `valid_until` (ISO 8601 with offset). */
    const DATE_FORMAT = 'Y-m-d\TH:i:sP';

    /** Rule count bounds accepted by the API. */
    const MIN_RULES = 1;

    const MAX_RULES = 50;

    /** Minimum click budget accepted by the API. */
    const MIN_MAX_CLICKS = 1;

    /** Maximum title length accepted by the API, in characters. */
    const MAX_TITLE_LENGTH = 64;

    /** @var string|null */
    private $domain;

    /** @var string|null */
    private $title;

    /** @var bool|null */
    private $forwardQuery;

    /** @var array<string, mixed>|null */
    private $callbackData;

    /** @var CreateLinkRule[] */
    private $rules;

    /** @var string|null */
    private $domainStrategy;

    /** @var string|null */
    private $domainGroup;

    /** @var \DateTimeInterface|null */
    private $validSince;

    /** @var \DateTimeInterface|null */
    private $validUntil;

    /** @var int|null */
    private $maxClicks;

    /**
     * Pick the domain in exactly one way: either an explicit $domain, or a
     * $domainStrategy (with an optional $domainGroup), or neither (the server
     * falls back to the default domain). Contradictory combinations are rejected
     * up front with an {@see InvalidRequestException}.
     *
     * @param  array<string, mixed>|null  $callbackData
     * @param  CreateLinkRule[]  $rules
     * @param  string|null  $domainStrategy  Domain auto-pick strategy: `random` / `round_robin` / `coldest`.
     *                                       Mutually exclusive with $domain; required when $domainGroup is set.
     * @param  string|null  $domainGroup  Group code restricting the auto-pick pool. Requires $domainStrategy.
     * @param  \DateTimeInterface|null  $validSince  Start of the activity window; the link answers 404 before it.
     * @param  \DateTimeInterface|null  $validUntil  End of the activity window (inclusive, not before $validSince).
     * @param  int|null  $maxClicks  Click budget (>= 1, every click counts, bots included); 404 once exhausted.
     *
     * @throws InvalidRequestException Contradictory domain options, an inverted
     *                                 activity window, or a non-positive $maxClicks.
     */
    public function __construct(
        ?string $domain,
        ?string $title,
        ?bool $forwardQuery,
        ?array $callbackData,
        array $rules,
        ?string $domainStrategy = null,
        ?string $domainGroup = null,
        ?\DateTimeInterface $validSince = null,
        ?\DateTimeInterface $validUntil = null,
        ?int $maxClicks = null
    ) {
        $this->domain = $domain;
        $this->title = $title;
        $this->forwardQuery = $forwardQuery;
        $this->callbackData = $callbackData;
        $this->rules = $rules;
        $this->domainStrategy = $domainStrategy;
        $this->domainGroup = $domainGroup;
        $this->validSince = $validSince;
        $this->validUntil = $validUntil;
        $this->maxClicks = $maxClicks;

        $this->assertValidDomainOptions();
        $this->assertValidRuleCount();
        $this->assertValidLimits();
    }

    /**
     * Enforce the contract's rule list bounds (1..50, order = priority)
     * before any HTTP round trip.
     *
     * @throws InvalidRequestException
     */
    private function assertValidRuleCount(): void
    {
        $count = count($this->rules);
        if ($count < self::MIN_RULES || $count > self::MAX_RULES) {
            throw new InvalidRequestException(sprintf(
                'A link needs between %d and %d rules.',
                self::MIN_RULES,
                self::MAX_RULES
            ));
        }
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

    /**
     * Reject obviously broken field limits before any HTTP round trip:
     * an activity window that ends before it starts, a click budget below
     * the server minimum, or an overlong title.
     *
     * @throws InvalidRequestException
     */
    private function assertValidLimits(): void
    {
        if ($this->validSince !== null && $this->validUntil !== null && $this->validUntil < $this->validSince) {
            throw new InvalidRequestException(
                '`validUntil` must not be earlier than `validSince`.'
            );
        }

        if ($this->title !== null && preg_match('/^.{0,'.self::MAX_TITLE_LENGTH.'}$/us', $this->title) !== 1) {
            throw new InvalidRequestException(sprintf(
                'A title must be valid UTF-8 and must not exceed %d characters.',
                self::MAX_TITLE_LENGTH
            ));
        }

        if ($this->maxClicks !== null && $this->maxClicks < self::MIN_MAX_CLICKS) {
            throw new InvalidRequestException(
                sprintf('`maxClicks` must be at least %d.', self::MIN_MAX_CLICKS)
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

    /**
     * @return array<string, mixed>|null
     */
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

    public function getValidSince(): ?\DateTimeInterface
    {
        return $this->validSince;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function getMaxClicks(): ?int
    {
        return $this->maxClicks;
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

        if ($this->validSince !== null) {
            $payload['valid_since'] = $this->validSince->format(self::DATE_FORMAT);
        }

        if ($this->validUntil !== null) {
            $payload['valid_until'] = $this->validUntil->format(self::DATE_FORMAT);
        }

        if ($this->maxClicks !== null) {
            $payload['max_clicks'] = $this->maxClicks;
        }

        return $payload;
    }
}
