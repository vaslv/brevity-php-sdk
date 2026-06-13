<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class DomainGroup
{
    /** @var string */
    private $code;

    /** @var string */
    private $name;

    /** @var int */
    private $domainsCount;

    public function __construct(string $code, string $name, int $domainsCount)
    {
        $this->code = $code;
        $this->name = $name;
        $this->domainsCount = $domainsCount;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDomainsCount(): int
    {
        return $this->domainsCount;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['code'],
            (string) $data['name'],
            isset($data['domains_count']) ? (int) $data['domains_count'] : 0
        );
    }
}
