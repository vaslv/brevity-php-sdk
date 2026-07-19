<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

use Vaslv\Brevity\Exceptions\InvalidRequestException;

class CreateLinkRule
{
    /** Maximum number of conditions per rule accepted by the API. */
    const MAX_CONDITIONS = 10;

    /** @var string */
    private $url;

    /** @var CreateLinkCondition[] */
    private $conditions;

    /** @var string|null */
    private $transitionMode;

    /**
     * A transition rule: a target URL plus the conditions gating it.
     *
     * Conditions combine with AND semantics — the rule wins only when all of
     * them match; an empty list makes the rule unconditional (usually placed
     * last as the fallback).
     *
     * @param  CreateLinkCondition[]  $conditions  Up to 10 conditions, ANDed together.
     * @param  string|null  $transitionMode  `direct` / `delayed` / `manual` (null means `direct`).
     *
     * @throws InvalidRequestException More than 10 conditions.
     */
    public function __construct(string $url, array $conditions = [], ?string $transitionMode = null)
    {
        if (count($conditions) > self::MAX_CONDITIONS) {
            throw new InvalidRequestException(
                sprintf('A rule accepts at most %d conditions.', self::MAX_CONDITIONS)
            );
        }

        $this->url = $url;
        $this->conditions = array_values($conditions);
        $this->transitionMode = $transitionMode;
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

        return new self(
            (string) $data['url'],
            $conditions,
            isset($data['transition_mode']) ? (string) $data['transition_mode'] : null
        );
    }
}
