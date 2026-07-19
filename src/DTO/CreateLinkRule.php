<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

use Vaslv\Brevity\Exceptions\InvalidRequestException;

class CreateLinkRule
{
    /** Maximum number of conditions per rule accepted by the API. */
    const MAX_CONDITIONS = 10;

    /** A/B split size bounds accepted by the API. */
    const MIN_VARIANTS = 2;

    const MAX_VARIANTS = 20;

    /** @var string */
    private $url;

    /** @var CreateLinkCondition[] */
    private $conditions;

    /** @var string|null */
    private $transitionMode;

    /** @var CreateLinkVariant[] */
    private $variants;

    /**
     * A transition rule: a target URL plus the conditions gating it.
     *
     * Conditions combine with AND semantics — the rule wins only when all of
     * them match; an empty list makes the rule unconditional (usually placed
     * last as the fallback).
     *
     * With $variants the rule becomes an A/B split: when it wins, the server
     * picks a variant by weight (sticky per visitor). The rule $url stays
     * required — it is the fallback target if the variants are removed later.
     *
     * @param  CreateLinkCondition[]  $conditions  Up to 10 conditions, ANDed together.
     * @param  string|null  $transitionMode  `direct` / `delayed` / `manual` (null means `direct`).
     * @param  CreateLinkVariant[]  $variants  Either empty (no split) or 2..20 weighted targets.
     *
     * @throws InvalidRequestException More than 10 conditions, or a variant count outside 2..20.
     */
    public function __construct(string $url, array $conditions = [], ?string $transitionMode = null, array $variants = [])
    {
        if (count($conditions) > self::MAX_CONDITIONS) {
            throw new InvalidRequestException(
                sprintf('A rule accepts at most %d conditions.', self::MAX_CONDITIONS)
            );
        }

        if ($variants !== [] && (count($variants) < self::MIN_VARIANTS || count($variants) > self::MAX_VARIANTS)) {
            throw new InvalidRequestException(sprintf(
                'An A/B split needs between %d and %d variants.',
                self::MIN_VARIANTS,
                self::MAX_VARIANTS
            ));
        }

        $this->url = $url;
        $this->conditions = array_values($conditions);
        $this->transitionMode = $transitionMode;
        $this->variants = array_values($variants);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return CreateLinkCondition[]
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getTransitionMode(): ?string
    {
        return $this->transitionMode;
    }

    /**
     * @return CreateLinkVariant[]
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['url' => $this->url];

        if ($this->conditions !== []) {
            $payload['conditions'] = [];
            foreach ($this->conditions as $condition) {
                $payload['conditions'][] = $condition->toArray();
            }
        }

        if ($this->variants !== []) {
            $payload['variants'] = [];
            foreach ($this->variants as $variant) {
                $payload['variants'][] = $variant->toArray();
            }
        }

        if ($this->transitionMode !== null) {
            $payload['transition_mode'] = $this->transitionMode;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $conditions = [];
        if (isset($data['conditions']) && is_array($data['conditions'])) {
            foreach ($data['conditions'] as $condition) {
                if (is_array($condition)) {
                    $conditions[] = CreateLinkCondition::fromArray($condition);
                }
            }
        }

        $variants = [];
        if (isset($data['variants']) && is_array($data['variants'])) {
            foreach ($data['variants'] as $variant) {
                if (is_array($variant)) {
                    $variants[] = CreateLinkVariant::fromArray($variant);
                }
            }
        }

        return new self(
            (string) $data['url'],
            $conditions,
            isset($data['transition_mode']) ? (string) $data['transition_mode'] : null,
            $variants
        );
    }
}
