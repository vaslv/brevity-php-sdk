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

    /** @var CreateLinkVariant[] */
    private $variants;

    /**
     * @param  CreateLinkCondition[]  $conditions
     * @param  CreateLinkVariant[]  $variants
     */
    public function __construct(string $url, array $conditions = [], ?string $transitionMode = null, array $variants = [])
    {
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

        // The deprecated single `condition` key mirrors conditions[0] and is ignored.
        return new self(
            (string) $data['url'],
            $conditions,
            isset($data['transition_mode']) ? (string) $data['transition_mode'] : null,
            $variants
        );
    }
}
