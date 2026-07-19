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
use Vaslv\Brevity\Exceptions\ForbiddenException;
use Vaslv\Brevity\Exceptions\MissingAbilityException;
use Vaslv\Brevity\Exceptions\NotFoundException;
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
     * @throws ApiException Other 4xx/5xx responses (`http-error`, `server-error`).
     * @throws AuthenticationException HTTP 401 `unauthenticated`.
     * @throws ForbiddenException HTTP 403 `forbidden` / `missing-ability`.
     * @throws InvalidRequestException Contradictory domain options (see {@see CreateLinkRequest}).
     * @throws RateLimitException HTTP 429 `too-many-requests`.
     * @throws TransportException Network/timeout failure.
     * @throws ValidationException HTTP 422 `validation-error`.
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
     * Create a short link and its transition rules via `POST /api/v1/links`.
     *
     * Network failures and 5xx responses are retried up to the configured
     * `retries` count; 4xx responses are surfaced immediately as typed exceptions.
     *
     * @throws ApiException Other 4xx/5xx responses (`http-error`, `server-error`).
     * @throws AuthenticationException HTTP 401 `unauthenticated`.
     * @throws ForbiddenException HTTP 403 `forbidden` / `missing-ability`.
     * @throws NotFoundException HTTP 404 `not-found`.
     * @throws RateLimitException HTTP 429 `too-many-requests`.
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 `validation-error`.
     */
    public function createLink(CreateLinkRequest $request): CreateLinkResponse
    {
        $response = $this->send('POST', '/api/v1/links', ['json' => $request->toArray()]);

        $payload = $this->decodeBody((string) $response->getBody());
        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new ApiException('Unexpected response format from API.', $response->getStatusCode(), $payload);
        }

        return CreateLinkResponse::fromArray($payload['data']);
    }

    /**
     * List short-link domains via `GET /api/v1/domains`.
     *
     * Without a group returns all domains; pass $group (a group code) to restrict
     * the list to a single domain group. Use the returned {@see Domain::getDomain()}
     * value as the `domain` of a link, or a {@see DomainGroup} code with a strategy.
     *
     * @param  string|null  $group  Domain group code; the group must exist (else 422).
     * @return Domain[] Sorted by domain.
     *
     * @throws ApiException Other 4xx/5xx responses (`http-error`, `server-error`).
     * @throws AuthenticationException HTTP 401 `unauthenticated`.
     * @throws ForbiddenException HTTP 403 `forbidden` / `missing-ability`.
     * @throws RateLimitException HTTP 429 `too-many-requests`.
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 `validation-error` (e.g. unknown group code).
     */
    public function listDomains(?string $group = null): array
    {
        $options = $group === null ? [] : ['query' => ['group' => $group]];
        $response = $this->send('GET', '/api/v1/domains', $options);

        $domains = [];
        foreach ($this->extractDataList($response) as $item) {
            if (is_array($item)) {
                $domains[] = Domain::fromArray($item);
            }
        }

        return $domains;
    }

    /**
     * List domain groups via `GET /api/v1/domain-groups`.
     *
     * Use a returned {@see DomainGroup::getCode()} as the `$group` of
     * {@see listDomains()} or the `domainGroup` of a {@see CreateLinkRequest}.
     *
     * @return DomainGroup[] Sorted by name.
     *
     * @throws ApiException Other 4xx/5xx responses (`http-error`, `server-error`).
     * @throws AuthenticationException HTTP 401 `unauthenticated`.
     * @throws ForbiddenException HTTP 403 `forbidden` / `missing-ability`.
     * @throws RateLimitException HTTP 429 `too-many-requests`.
     * @throws TransportException Network/timeout failure (retries exhausted).
     * @throws ValidationException HTTP 422 `validation-error`.
     */
    public function listDomainGroups(): array
    {
        $response = $this->send('GET', '/api/v1/domain-groups');

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
     * @throws ApiException Any error response, as a typed subclass per the problem `type`.
     * @throws TransportException Network/timeout failure (retries exhausted).
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
     * Map an RFC 7807 problem response to the matching typed exception and throw it.
     *
     * Dispatch follows the stable machine code in the problem `type` field;
     * the HTTP status is only a fallback for responses that carry no `type`
     * (e.g. a proxy answering instead of the API). An unknown `type` maps to
     * the base {@see ApiException} so future codes degrade gracefully.
     *
     * @throws ApiException
     */
    private function throwForStatus(int $statusCode, ResponseInterface $response): void
    {
        $payload = $this->decodeBody((string) $response->getBody());

        $problemType = null;
        if (isset($payload['type']) && is_string($payload['type']) && $payload['type'] !== '') {
            $problemType = $payload['type'];
        }

        switch ($problemType !== null ? $problemType : $this->problemTypeForStatus($statusCode)) {
            case 'unauthenticated':
                throw new AuthenticationException(
                    $this->extractMessage($payload, 'Unauthenticated.'),
                    $statusCode,
                    $payload,
                    $problemType
                );
            case 'missing-ability':
                throw new MissingAbilityException(
                    $this->extractMessage($payload, 'The token is missing a required ability.'),
                    $statusCode,
                    $payload,
                    $problemType
                );
            case 'forbidden':
                throw new ForbiddenException(
                    $this->extractMessage($payload, 'Forbidden.'),
                    $statusCode,
                    $payload,
                    $problemType
                );
            case 'not-found':
                throw new NotFoundException(
                    $this->extractMessage($payload, 'Not found.'),
                    $statusCode,
                    $payload,
                    $problemType
                );
            case 'validation-error':
                $errors = [];
                if (isset($payload['errors']) && is_array($payload['errors'])) {
                    $errors = $payload['errors'];
                }

                throw new ValidationException(
                    $this->extractMessage($payload, 'The request failed validation.'),
                    $errors,
                    $statusCode,
                    $payload,
                    $problemType
                );
            case 'too-many-requests':
                throw new RateLimitException(
                    $this->extractMessage($payload, 'Rate limit exceeded.'),
                    $statusCode,
                    $payload,
                    $this->parseRetryAfter($response->getHeaderLine('Retry-After')),
                    $problemType
                );
            default:
                throw new ApiException(
                    $this->extractMessage($payload, 'API request failed.'),
                    $statusCode,
                    $payload,
                    $problemType
                );
        }
    }

    /**
     * Fallback problem type for a bare status code, used when the body has no `type`.
     */
    private function problemTypeForStatus(int $statusCode): ?string
    {
        switch ($statusCode) {
            case 401:
                return 'unauthenticated';
            case 403:
                return 'forbidden';
            case 404:
                return 'not-found';
            case 422:
                return 'validation-error';
            case 429:
                return 'too-many-requests';
            default:
                return null;
        }
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
     * Best human-readable message from an RFC 7807 problem body:
     * `detail`, then `title`, then the given default.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractMessage(array $payload, string $default): string
    {
        foreach (['detail', 'title'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && $payload[$field] !== '') {
                return $payload[$field];
            }
        }

        return $default;
    }
}
