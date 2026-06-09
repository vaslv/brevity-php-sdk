<?php

declare(strict_types=1);

namespace Vaslv\Brevity;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkResponse;
use Vaslv\Brevity\DTO\CreateLinkRule;
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
     *
     * @param  array<string, mixed>|null  $callbackData
     *
     * @throws ApiException             Other 4xx/5xx responses.
     * @throws AuthenticationException  HTTP 401 (missing/invalid token).
     * @throws RateLimitException       HTTP 429 (rate limit exceeded).
     * @throws TransportException       Network/timeout failure.
     * @throws ValidationException      HTTP 422 (validation error).
     */
    public function createSimpleLink(
        string $url,
        ?string $domain = null,
        ?string $title = null,
        ?bool $forwardQuery = null,
        ?array $callbackData = null,
        ?string $transitionMode = null
    ): CreateLinkResponse {
        return $this->createLink(new CreateLinkRequest(
            $domain,
            $title,
            $forwardQuery,
            $callbackData,
            [
                new CreateLinkRule($url, null, $transitionMode),
            ]
        ));
    }

    /**
     * Create a short link and its transition rules via `POST /api/links`.
     *
     * Network failures and 5xx responses are retried up to the configured
     * `retries` count; 4xx responses are surfaced immediately as typed exceptions.
     *
     * @throws ApiException             Other 4xx/5xx responses.
     * @throws AuthenticationException  HTTP 401 (missing/invalid token).
     * @throws RateLimitException       HTTP 429 (rate limit exceeded).
     * @throws TransportException       Network/timeout failure (retries exhausted).
     * @throws ValidationException      HTTP 422 (validation error).
     */
    public function createLink(CreateLinkRequest $request): CreateLinkResponse
    {
        $attempt = 0;
        $maxAttempts = $this->maxRetries + 1;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = $this->httpClient->request(
                    'POST',
                    '/api/links',
                    [
                        'headers' => $this->buildHeaders(),
                        'json' => $request->toArray(),
                    ]
                );

                $payload = $this->decodeBody((string) $response->getBody());
                if (! isset($payload['data']) || ! is_array($payload['data'])) {
                    throw new ApiException('Unexpected response format from API.', $response->getStatusCode(), $payload);
                }

                return CreateLinkResponse::fromArray($payload['data']);
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
                $payload = $this->decodeBody((string) $response->getBody());

                if ($statusCode >= 500 && $attempt < $maxAttempts) {
                    continue;
                }

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
            } catch (\Throwable $exception) {
                throw TransportException::fromThrowable($exception);
            }
        }

        throw new TransportException('Transport error: retries exhausted.');
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
