<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class CreateLinkRule
{
    /** @var string */
    private $url;

    /** @var CreateLinkCondition|null */
    private $condition;

    /** @var string|null */
    private $transitionMode;

    public function __construct(string $url, ?CreateLinkCondition $condition = null, ?string $transitionMode = null)
    {
        $this->url = $url;
        $this->condition = $condition;
        $this->transitionMode = $transitionMode;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCondition(): ?CreateLinkCondition
    {
        return $this->condition;
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

        if ($this->condition !== null) {
            $payload['condition'] = $this->condition->toArray();
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
        return new self(
            (string) $data['url'],
            isset($data['condition']) && is_array($data['condition'])
                ? CreateLinkCondition::fromArray($data['condition'])
                : null,
            isset($data['transition_mode']) ? (string) $data['transition_mode'] : null
        );
    }
}
