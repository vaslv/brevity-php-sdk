<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

/**
 * Click summary of a link, aggregated from pre-computed counters.
 *
 * Clicks are recorded asynchronously, so the numbers may lag behind
 * reality by a few seconds.
 */
class LinkClicks
{
    /** @var int */
    private $total;

    /** @var int */
    private $nonBots;

    public function __construct(int $total, int $nonBots)
    {
        $this->total = $total;
        $this->nonBots = $nonBots;
    }

    /**
     * All clicks, bots included.
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Clicks whose User-Agent was not classified as a bot.
     */
    public function getNonBots(): int
    {
        return $this->nonBots;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['total']) ? (int) $data['total'] : 0,
            isset($data['non_bots']) ? (int) $data['non_bots'] : 0
        );
    }
}
