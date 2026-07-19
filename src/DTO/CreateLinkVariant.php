<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

use Vaslv\Brevity\Exceptions\InvalidRequestException;

class CreateLinkVariant
{
    /** Weight bounds accepted by the API. */
    const MIN_WEIGHT = 1;

    const MAX_WEIGHT = 1000;

    /** Maximum label length accepted by the API, in characters. */
    const MAX_LABEL_LENGTH = 64;

    /** @var string */
    private $url;

    /** @var int */
    private $weight;

    /** @var string|null */
    private $label;

    /**
     * A weighted A/B split target: the variant's traffic share is its weight
     * divided by the sum of all weights in the rule (no need to sum to 100).
     *
     * @param  int  $weight  Integer 1..1000.
     * @param  string|null  $label  Optional marker (up to 64 characters) echoed
     *                              back in callbacks via `{{click.variant}}`.
     *
     * @throws InvalidRequestException Weight out of range or an overlong label.
     */
    public function __construct(string $url, int $weight, ?string $label = null)
    {
        if ($weight < self::MIN_WEIGHT || $weight > self::MAX_WEIGHT) {
            throw new InvalidRequestException(sprintf(
                'A variant weight must be an integer between %d and %d.',
                self::MIN_WEIGHT,
                self::MAX_WEIGHT
            ));
        }

        if ($label !== null && preg_match('/^.{0,'.self::MAX_LABEL_LENGTH.'}$/us', $label) !== 1) {
            throw new InvalidRequestException(sprintf(
                'A variant label must be valid UTF-8 and must not exceed %d characters.',
                self::MAX_LABEL_LENGTH
            ));
        }

        $this->url = $url;
        $this->weight = $weight;
        $this->label = $label;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'url' => $this->url,
            'weight' => $this->weight,
        ];

        if ($this->label !== null) {
            $payload['label'] = $this->label;
        }

        return $payload;
    }

    /**
     * Hydrate from an API response without re-running request validation:
     * responses must stay readable even if the server relaxes the bounds.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $variant = new self((string) $data['url'], self::MIN_WEIGHT);
        $variant->weight = isset($data['weight']) ? (int) $data['weight'] : self::MIN_WEIGHT;
        $variant->label = isset($data['label']) ? (string) $data['label'] : null;

        return $variant;
    }

    /**
     * Hydrate a list of variants, skipping non-array entries.
     *
     * @param  array<int, mixed>  $items
     * @return self[]
     */
    public static function listFromArray(array $items): array
    {
        $variants = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $variants[] = self::fromArray($item);
            }
        }

        return $variants;
    }
}
