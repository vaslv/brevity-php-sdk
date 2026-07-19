<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class CreateLinkCondition
{
    /** @var string */
    private $type;

    /** @var array<string, mixed>|null */
    private $data;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(string $type, ?array $data = null)
    {
        $this->type = $type;
        $this->data = $data;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['type' => $this->type];

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['type'],
            isset($data['data']) && is_array($data['data']) ? $data['data'] : null
        );
    }

    /**
     * Hydrate a list of conditions, skipping non-array entries.
     *
     * @param  array<int, mixed>  $items
     * @return self[]
     */
    public static function listFromArray(array $items): array
    {
        $conditions = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $conditions[] = self::fromArray($item);
            }
        }

        return $conditions;
    }
}
