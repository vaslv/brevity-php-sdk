<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class CreateLinkResponseRule
{
    /** @var string */
    private $url;

    /** @var CreateLinkCondition[] */
    private $conditions;

    /** @var string|null */
    private $transitionMode;

    /**
     * @param  CreateLinkCondition[]  $conditions
     */
    public function __construct(string $url, array $conditions = [], ?string $transitionMode = null)
    {
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

        // The deprecated single `condition` key mirrors conditions[0] and is ignored.
        return new self(
            (string) $data['url'],
            $conditions,
            isset($data['transition_mode']) ? (string) $data['transition_mode'] : null
        );
    }
}
