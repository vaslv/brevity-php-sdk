<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

class Domain
{
    /** @var string */
    private $domain;

    /** @var string */
    private $url;

    /** @var bool */
    private $isDefault;

    public function __construct(string $domain, string $url, bool $isDefault)
    {
        $this->domain = $domain;
        $this->url = $url;
        $this->isDefault = $isDefault;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['domain'],
            (string) $data['url'],
            isset($data['is_default']) ? (bool) $data['is_default'] : false
        );
    }
}
