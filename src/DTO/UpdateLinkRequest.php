<?php

declare(strict_types=1);

namespace Vaslv\Brevity\DTO;

use Vaslv\Brevity\Exceptions\InvalidRequestException;

/**
 * Partial update for `PATCH /api/v1/links/{code}`.
 *
 * Only fields whose setter was called are serialized: an untouched field
 * keeps its server-side value, while an explicit null clears the value
 * (e.g. `setMaxClicks(null)` lifts the click budget). `code`, `domain` and
 * the owning service cannot be changed. The merged activity window is
 * validated by the server (422 when `valid_until` ends up before
 * `valid_since`).
 */
class UpdateLinkRequest
{
    /** @var array<string, mixed> */
    private $payload = [];

    /**
     * @throws InvalidRequestException An overlong title.
     */
    public function setTitle(?string $title): self
    {
        if ($title !== null && preg_match('/^.{0,'.CreateLinkRequest::MAX_TITLE_LENGTH.'}$/us', $title) !== 1) {
            throw new InvalidRequestException(sprintf(
                'A title must be valid UTF-8 and must not exceed %d characters.',
                CreateLinkRequest::MAX_TITLE_LENGTH
            ));
        }

        $this->payload['title'] = $title;

        return $this;
    }

    public function setForwardQuery(?bool $forwardQuery): self
    {
        $this->payload['forward_query'] = $forwardQuery;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $callbackData
     */
    public function setCallbackData(?array $callbackData): self
    {
        $this->payload['callback_data'] = $callbackData;

        return $this;
    }

    public function setValidSince(?\DateTimeInterface $validSince): self
    {
        $this->payload['valid_since'] = $validSince === null
            ? null
            : $validSince->format(CreateLinkRequest::DATE_FORMAT);

        return $this;
    }

    public function setValidUntil(?\DateTimeInterface $validUntil): self
    {
        $this->payload['valid_until'] = $validUntil === null
            ? null
            : $validUntil->format(CreateLinkRequest::DATE_FORMAT);

        return $this;
    }

    /**
     * @throws InvalidRequestException A budget below the server minimum of 1.
     */
    public function setMaxClicks(?int $maxClicks): self
    {
        if ($maxClicks !== null && $maxClicks < CreateLinkRequest::MIN_MAX_CLICKS) {
            throw new InvalidRequestException(
                sprintf('`maxClicks` must be at least %d.', CreateLinkRequest::MIN_MAX_CLICKS)
            );
        }

        $this->payload['max_clicks'] = $maxClicks;

        return $this;
    }

    /**
     * Replace the whole rule list (same bounds as at creation: 1..50,
     * order = priority). Rules cannot be cleared to null — a link always
     * keeps at least one rule.
     *
     * @param  CreateLinkRule[]  $rules
     *
     * @throws InvalidRequestException A rule count outside 1..50.
     */
    public function setRules(array $rules): self
    {
        $count = count($rules);
        if ($count < CreateLinkRequest::MIN_RULES || $count > CreateLinkRequest::MAX_RULES) {
            throw new InvalidRequestException(sprintf(
                'A link needs between %d and %d rules.',
                CreateLinkRequest::MIN_RULES,
                CreateLinkRequest::MAX_RULES
            ));
        }

        $serialized = [];
        foreach ($rules as $rule) {
            $serialized[] = $rule->toArray();
        }
        $this->payload['rules'] = $serialized;

        return $this;
    }

    /**
     * True when no setter has been called — such a patch would be a no-op.
     */
    public function isEmpty(): bool
    {
        return $this->payload === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
