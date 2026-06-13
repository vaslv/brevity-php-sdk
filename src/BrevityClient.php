<?php

declare(strict_types=1);

namespace Vaslv\Brevity;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkResponse;
use Vaslv\Brevity\DTO\CreateLinkRule;
use Vaslv\Brevity\DTO\Domain;
use Vaslv\Brevity\DTO\DomainGroup;
use Vaslv\Brevity\Exceptions\ApiException;
use Vaslv\Brevity\Exceptions\AuthenticationException;
use Vaslv\Brevity\Exceptions\RateLimitException;
use Vaslv\Brevity\Exceptions\TransportException;
use Vaslv\Brevity\Exceptions\ValidationException;

class BrevityClient
{
    /** @var ClientInterface */
    private $httpClient;

    /** @var string */
    private $token;

    /** @var int */
    private $maxRetries;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        $this->token = isset($config['token']) ? (string) $config['token'] : '';
        $this->maxRetries = isset($config['retries']) ? (int) $config['retries'] : 1;

        $this->httpClient = $httpClient ?: new Client(
            [
                'base_uri' => isset($config['base_uri']) ? (string) $config['base_uri'] : '',
                'timeout' => isset($config['timeout']) ? (float) $config['timeout'] : 7.0,
                'connect_timeout' => isset($config['connect_timeout']) ? (float) $config['connect_timeout'] : 5.0,
            ]
        );
    }

    /**
     * Create a short link with a single target URL and no condition.
     *
     * Convenience wrapper around {@see createLink()} for the common one-rule case.
     * Pass $domainStrategy (and optionally $domainGroup) instead of $domain to
     * have the server auto-pick the domain; $domain and $domainStrategy are
     * mutually exclusive.
     *
     * @param  array<string, mixed>|null  $callbackData
     * @param  string|null  $domainStrategy  Domain auto-pick strategy: `random` / `round_robin` / `coldest`.
     * @param  string|null  $domainGroup  Group code restricting the auto-pick pool. Requires $domainStrategy.
     *
     * @throws ApiException Other 4xx/5xx responses.
     * @throws AuthenticationException HTTP 401 (missing/invalid token).
     * @throws InvalidRequestException Contradictory domain options (see {@see CreateLinkRequest}).
     * @throws RateLimitException HTTP 429 (rate limit exceeded).
     * @throws TransportException Network/timeout failure.
     * @throws ValidationException HTTP 422 (validation error).
     */
    public function createSimpleLink(
        string $url,
        ?string $domain = null,
        ?string $title = null,
        ?bool $forwardQuery = null,
        ?array $callbackData = null,
        ?string $transitionMode = null,
        ?string $domainStrategy = null,
        ?string $domainGroup = null
    ): CreateLinkResponse {
        return $this->createLink(new CreateLinkRequest(
            $domain,
            $title,
            $forwardQuery,
            $callbackData,
            [
                new CreateLinkRule($url, null, $transitionMode),
            ],
            $domainStrategy,
            $domainGroup
        ));
    }

    /**
     * Create a short link and its transition rules via `POST /api/links`.
     *
     * Network failures and 5xx responses are retried up to the configured
     * `retries` count; 4xx responses are surfaced immediately as typed exceptions.
     *
     * @throws ApiException Other 4xx/5xx responses.
     * @throws AuthenticationException HTTP 401 (missing/invalid token).
     * @throws RateLimitException HTTP 429 (rate limit exceeded).
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 (validation error).
     */
    public function createLink(CreateLinkRequest $request): CreateLinkResponse
    {
        $response = $this->send('POST', '/api/links', ['json' => $request->toArray()]);

        $payload = $this->decodeBody((string) $response->getBody());
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new ApiException('Unexpected response format from API.', $response->getStatusCode(), $payload);
        }

        return CreateLinkResponse::fromArray($payload['data']);
    }

    /**
     * List short-link domains via `GET /api/domains`.
     *
     * Without a group returns all domains; pass $group (a group code) to restrict
     * the list to a single domain group. Use the returned {@see Domain::getDomain()}
     * value as the `domain` of a link, or a {@see DomainGroup} code with a strategy.
     *
     * @param  string|null  $group  Domain group code; the group must exist (else 422).
     * @return Domain[] Sorted by domain.
     *
     * @throws ApiException Other 4xx/5xx responses.
     * @throws AuthenticationException HTTP 401 (missing/invalid token).
     * @throws RateLimitException HTTP 429 (rate limit exceeded).
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 (e.g. unknown group code).
     */
    public function listDomains(?string $group = null): array
    {
        $options = $group === null ? [] : ['query' => ['group' => $group]];
        $response = $this->send('GET', '/api/domains', $options);

        $domains = [];
        foreach ($this->extractDataList($response) as $item) {
            if (is_array($item)) {
                $domains[] = Domain::fromArray($item);
            }
        }

        return $domains;
    }

    /**
     * List domain groups via `GET /api/domain-groups`.
     *
     * Use a returned {@see DomainGroup::getCode()} as the `$group` of
     * {@see listDomains()} or the `domainGroup` of a {@see CreateLinkRequest}.
     *
     * @return DomainGroup[] Sorted by name.
     *
     * @throws ApiException Other 4xx/5xx responses.
     * @throws AuthenticationException HTTP 401 (missing/invalid token).
     * @throws RateLimitException HTTP 429 (rate limit exceeded).
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 (validation error).
     */
    public function listDomainGroups(): array
    {
        $response = $this->send('GET', '/api/domain-groups');

        $groups = [];
        foreach ($this->extractDataList($response) as $item) {
            if (is_array($item)) {
                $groups[] = DomainGroup::fromArray($item);
            }
        }

        return $groups;
    }

    /**
     * Send a request with retries for network failures and 5xx; map 4xx/5xx to typed exceptions.
     *
     * Network failures and 5xx responses are retried up to the configured
     * `retries` count; 4xx responses are surfaced immediately.
     *
     * @param  array<string, mixed>  $options  Extra Guzzle request options (json body, query, ...).
     *
     * @throws ApiException Other 4xx/5xx responses.
     * @throws AuthenticationException HTTP 401 (missing/invalid token).
     * @throws RateLimitException HTTP 429 (rate limit exceeded).
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 (validation error).
     */
    private function send(string $method, string $uri, array $options = []): ResponseInterface
    {
        $attempt = 0;
        $maxAttempts = $this->maxRetries + 1;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                return $this->httpClient->request(
                    $method,
                    $uri,
                    ['headers' => $this->buildHeaders()] + $options
                );
            } catch (ConnectException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw TransportException::fromThrowable($exception);
                }
            } catch (RequestException $exception) {
                $response = $exception->getResponse();
                if ($response === null) {
                    if ($attempt >= $maxAttempts) {
                        throw TransportException::fromThrowable($exception);
                    }

                    continue;
                }

                $statusCode = (int) $response->getStatusCode();
                if ($statusCode >= 500 && $attempt < $maxAttempts) {
                    continue;
                }

                $this->throwForStatus($statusCode, $response);
            } catch (\Throwable $exception) {
                throw TransportException::fromThrowable($exception);
            }
        }

        throw new TransportException('Transport error: retries exhausted.');
    }

    /**
     * Map an error HTTP status to the matching typed exception and throw it.
     *
     * @throws ApiException
     * @throws AuthenticationException
     * @throws RateLimitException
     * @throws ValidationException
     */
    private function throwForStatus(int $statusCode, ResponseInterface $response): void
    {
        $payload = $this->decodeBody((string) $response->getBody());

        if ($statusCode === 401) {
            throw new AuthenticationException(
                $this->extractMessage($payload, 'Unauthenticated.'),
                $statusCode,
                $payload
            );
        }

        if ($statusCode === 422) {
            $errors = [];
            if (isset($payload['errors']) && is_array($payload['errors'])) {
                $errors = $payload['errors'];
            }

            throw new ValidationException(
                $this->extractMessage($payload, 'The given data was invalid.'),
                $errors,
                $statusCode,
                $payload
            );
        }

        if ($statusCode === 429) {
            throw new RateLimitException(
                $this->extractMessage($payload, 'Rate limit exceeded.'),
                $statusCode,
                $payload,
                $this->parseRetryAfter($response->getHeaderLine('Retry-After'))
            );
        }

        throw new ApiException(
            $this->extractMessage($payload, 'API request failed.'),
            $statusCode,
            $payload
        );
    }

    /**
     * Decode a `data`-wrapped list response, asserting the wrapper is present.
     *
     * @return array<int, mixed>
     */
    private function extractDataList(ResponseInterface $response): array
    {
        $payload = $this->decodeBody((string) $response->getBody());
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new ApiException('Unexpected response format from API.', $response->getStatusCode(), $payload);
        }

        return $payload['data'];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Parse the `Retry-After` header (delta-seconds form) into an int, or null when absent/non-numeric.
     */
    private function parseRetryAfter(string $header): ?int
    {
        if ($header !== '' && is_numeric($header)) {
            return (int) $header;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMessage(array $payload, string $default): string
    {
        if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            return $payload['message'];
        }

        return $default;
    }
}
