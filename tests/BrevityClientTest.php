<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Vaslv\Brevity\BrevityClient;
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkResponse;
use Vaslv\Brevity\DTO\CreateLinkResponseRule;
use Vaslv\Brevity\DTO\CreateLinkRule;
use Vaslv\Brevity\DTO\CreateLinkVariant;
use Vaslv\Brevity\DTO\UpdateLinkRequest;
use Vaslv\Brevity\Exceptions\ApiException;
use Vaslv\Brevity\Exceptions\AuthenticationException;
use Vaslv\Brevity\Exceptions\ForbiddenException;
use Vaslv\Brevity\Exceptions\InvalidRequestException;
use Vaslv\Brevity\Exceptions\MissingAbilityException;
use Vaslv\Brevity\Exceptions\NotFoundException;
use Vaslv\Brevity\Exceptions\RateLimitException;
use Vaslv\Brevity\Exceptions\TransportException;
use Vaslv\Brevity\Exceptions\ValidationException;

class BrevityClientTest extends TestCase
{
    public function test_create_simple_link_success(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(201, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/S1mple42',
                        'domain' => 'short.example.com',
                        'code' => 'S1mple42',
                        'title' => 'Single target',
                        'forward_query' => true,
                        'callback_data' => ['source' => 'sdk'],
                        'rules' => [
                            [
                                'url' => 'https://example.com/landing',
                                'transition_mode' => 'direct',
                            ],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);

        $client = new BrevityClient(
            [
                'base_uri' => 'https://api.example.com',
                'token' => 'test-token',
                'retries' => 0,
            ],
            $httpClient
        );

        $response = $client->createSimpleLink(
            'https://example.com/landing',
            'short.example.com',
            'Single target',
            true,
            ['source' => 'sdk'],
            'direct'
        );

        $this->assertSame('S1mple42', $response->getCode());
        $this->assertCount(1, $response->getRules());

        $body = json_decode((string) $container[0]['request']->getBody(), true);
        $this->assertSame('short.example.com', $body['domain']);
        $this->assertSame('https://example.com/landing', $body['rules'][0]['url']);
        $this->assertArrayNotHasKey('condition', $body['rules'][0]);
        $this->assertArrayNotHasKey('conditions', $body['rules'][0]);
        $this->assertSame('direct', $body['rules'][0]['transition_mode']);
    }

    public function test_create_link_success(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(201, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/AbC12345',
                        'domain' => 'short.example.com',
                        'code' => 'AbC12345',
                        'title' => 'Campaign link',
                        'forward_query' => true,
                        'callback_data' => ['campaign_id' => 'cmp-42'],
                        'rules' => [
                            [
                                'url' => 'https://example.com/landing?a=1&b=2',
                                'conditions' => [
                                    [
                                        'type' => 'time_before',
                                        'data' => ['before' => '2026-03-05T10:00:00+00:00'],
                                    ],
                                ],
                                'condition' => null,
                                'variants' => [],
                                'transition_mode' => 'delayed',
                            ],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);

        $client = new BrevityClient(
            [
                'base_uri' => 'https://api.example.com',
                'token' => 'test-token',
                'retries' => 0,
            ],
            $httpClient
        );

        $request = new CreateLinkRequest(
            'short.example.com',
            'Campaign link',
            true,
            ['campaign_id' => 'cmp-42'],
            [
                new CreateLinkRule(
                    'https://example.com/landing?b=2&a=1',
                    [new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00'])],
                    'delayed'
                ),
            ]
        );

        $response = $client->createLink($request);

        $this->assertSame('AbC12345', $response->getCode());
        $this->assertSame('short.example.com', $response->getDomain());
        $this->assertCount(1, $response->getRules());
        $this->assertSame('delayed', $response->getRules()[0]->getTransitionMode());
        $this->assertCount(1, $response->getRules()[0]->getConditions());
        $this->assertSame('time_before', $response->getRules()[0]->getConditions()[0]->getType());
        $this->assertCount(1, (array) $container);

        $lastRequest = $container[0]['request'];
        $this->assertSame('/api/v1/links', $lastRequest->getUri()->getPath());
        $this->assertSame('Bearer test-token', $lastRequest->getHeaderLine('Authorization'));
        $body = json_decode((string) $lastRequest->getBody(), true);
        $this->assertSame('short.example.com', $body['domain']);
        $this->assertSame('time_before', $body['rules'][0]['conditions'][0]['type']);
        $this->assertArrayNotHasKey('condition', $body['rules'][0]);
    }

    public function test_create_link_throws_authentication_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(401, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'unauthenticated',
                    'title' => 'Unauthenticated.',
                    'status' => 401,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'bad-token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('AuthenticationException was expected.');
        } catch (AuthenticationException $exception) {
            $this->assertSame('unauthenticated', $exception->getProblemType());
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_create_link_throws_validation_exception_with_errors(): void
    {
        $mock = new MockHandler(
            [
                new Response(422, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'validation-error',
                    'title' => 'The request failed validation.',
                    'status' => 422,
                    'detail' => 'The rules.0.condition.data.before field is required.',
                    'errors' => [
                        'rules.0.condition.data.before' => [
                            'The rules.0.condition.data.before field is required.',
                        ],
                    ],
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertSame('validation-error', $exception->getProblemType());
            $this->assertSame('The rules.0.condition.data.before field is required.', $exception->getMessage());
            $this->assertArrayHasKey('rules.0.condition.data.before', $exception->getErrors());
        }
    }

    public function test_create_link_throws_rate_limit_exception_with_retry_after(): void
    {
        $mock = new MockHandler(
            [
                new Response(
                    429,
                    ['Content-Type' => 'application/problem+json', 'Retry-After' => '30'],
                    (string) json_encode(['type' => 'too-many-requests', 'title' => 'Too Many Requests', 'status' => 429])
                ),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertSame('too-many-requests', $exception->getProblemType());
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertSame(30, $exception->getRetryAfter());
        }
    }

    public function test_create_link_throws_missing_ability_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(403, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'missing-ability',
                    'title' => 'Forbidden.',
                    'status' => 403,
                    'detail' => 'The token is missing the links:create ability.',
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('MissingAbilityException was expected.');
        } catch (MissingAbilityException $exception) {
            $this->assertInstanceOf(ForbiddenException::class, $exception);
            $this->assertSame('missing-ability', $exception->getProblemType());
            $this->assertSame('The token is missing the links:create ability.', $exception->getMessage());
        }
    }

    public function test_create_link_throws_forbidden_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(403, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'forbidden',
                    'title' => 'Forbidden.',
                    'status' => 403,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ForbiddenException was expected.');
        } catch (ForbiddenException $exception) {
            $this->assertNotInstanceOf(MissingAbilityException::class, $exception);
            $this->assertSame('forbidden', $exception->getProblemType());
        }
    }

    public function test_create_link_throws_not_found_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(404, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'not-found',
                    'title' => 'Not Found.',
                    'status' => 404,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('NotFoundException was expected.');
        } catch (NotFoundException $exception) {
            $this->assertSame('not-found', $exception->getProblemType());
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_http_error_problem_maps_to_base_api_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(405, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'http-error',
                    'title' => 'Method Not Allowed.',
                    'status' => 405,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ApiException was expected.');
        } catch (ApiException $exception) {
            $this->assertSame('http-error', $exception->getProblemType());
            $this->assertSame(405, $exception->getStatusCode());
        }
    }

    public function test_server_error_problem_maps_to_base_api_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(500, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'server-error',
                    'title' => 'Server Error.',
                    'status' => 500,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ApiException was expected.');
        } catch (ApiException $exception) {
            $this->assertSame('server-error', $exception->getProblemType());
            $this->assertSame(500, $exception->getStatusCode());
        }
    }

    public function test_error_without_problem_type_falls_back_to_status_mapping(): void
    {
        $mock = new MockHandler(
            [
                new Response(404, [], ''),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('NotFoundException was expected.');
        } catch (NotFoundException $exception) {
            $this->assertNull($exception->getProblemType());
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_authentication_fallback_without_problem_type(): void
    {
        $mock = new MockHandler([new Response(401, [], '')]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('AuthenticationException was expected.');
        } catch (AuthenticationException $exception) {
            $this->assertNull($exception->getProblemType());
        }
    }

    public function test_forbidden_fallback_without_problem_type(): void
    {
        $mock = new MockHandler([new Response(403, [], '')]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ForbiddenException was expected.');
        } catch (ForbiddenException $exception) {
            $this->assertNull($exception->getProblemType());
        }
    }

    public function test_validation_fallback_without_problem_type_surfaces_errors(): void
    {
        $mock = new MockHandler(
            [
                new Response(422, [], (string) json_encode([
                    'message' => 'The given data was invalid.',
                    'errors' => ['rules' => ['The rules field is required.']],
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertNull($exception->getProblemType());
            $this->assertArrayHasKey('rules', $exception->getErrors());
        }
    }

    public function test_rate_limit_fallback_without_problem_type(): void
    {
        $mock = new MockHandler([new Response(429, ['Retry-After' => '15'], '')]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertNull($exception->getProblemType());
            $this->assertSame(15, $exception->getRetryAfter());
        }
    }

    public function test_retry_after_header_with_junk_is_ignored(): void
    {
        $mock = new MockHandler([new Response(429, ['Retry-After' => '-5'], '')]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertNull($exception->getRetryAfter());
        }
    }

    public function test_retry_after_http_date_form_is_parsed(): void
    {
        $mock = new MockHandler(
            [
                // A past date clamps to 0; a future date yields positive seconds.
                new Response(429, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT'], ''),
                new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120)], ''),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $request = new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]);

        try {
            $client->createLink($request);
            $this->fail('RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertSame(0, $exception->getRetryAfter());
        }

        try {
            $client->createLink($request);
            $this->fail('RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertGreaterThan(100, $exception->getRetryAfter());
            $this->assertLessThanOrEqual(120, $exception->getRetryAfter());
        }
    }

    public function test_unknown_problem_type_falls_back_to_status_mapping(): void
    {
        $mock = new MockHandler(
            [
                new Response(403, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'brand-new-code',
                    'title' => 'Something new.',
                    'status' => 403,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ForbiddenException was expected.');
        } catch (ForbiddenException $exception) {
            $this->assertSame('brand-new-code', $exception->getProblemType());
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_unknown_problem_type_with_unmapped_status_maps_to_base_api_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(418, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'teapot',
                    'title' => 'I am a teapot.',
                    'status' => 418,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ApiException was expected.');
        } catch (ApiException $exception) {
            $this->assertSame('teapot', $exception->getProblemType());
            $this->assertSame(418, $exception->getStatusCode());
        }
    }

    public function test_create_link_returns_null_domain_when_response_domain_is_null(): void
    {
        $mock = new MockHandler(
            [
                new Response(201, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://app.example.com/NoD0main',
                        'domain' => null,
                        'code' => 'NoD0main',
                        'title' => null,
                        'forward_query' => false,
                        'callback_data' => null,
                        'rules' => [
                            ['url' => 'https://example.com/landing', 'condition' => null, 'transition_mode' => null],
                        ],
                    ],
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $response = $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com/landing')]));

        $this->assertNull($response->getDomain());
        $this->assertSame('NoD0main', $response->getCode());
    }

    public function test_get_link_success_with_click_summary(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/AbC12345',
                        'domain' => 'short.example.com',
                        'code' => 'AbC12345',
                        'title' => null,
                        'forward_query' => false,
                        'callback_data' => null,
                        'valid_since' => null,
                        'valid_until' => '2026-09-01T00:00:00+00:00',
                        'max_clicks' => 100,
                        'clicks' => ['total' => 42, 'non_bots' => 37],
                        'rules' => [
                            [
                                'url' => 'https://example.com/landing',
                                'conditions' => [],
                                'condition' => null,
                                'variants' => [],
                                'transition_mode' => null,
                            ],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $link = $client->getLink('AbC12345');

        $request = $container[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/links/AbC12345', $request->getUri()->getPath());
        $this->assertSame('Bearer token', $request->getHeaderLine('Authorization'));

        $this->assertInstanceOf(CreateLinkResponse::class, $link);
        $this->assertSame('AbC12345', $link->getCode());
        $this->assertNull($link->getValidSince());
        $this->assertSame('2026-09-01T00:00:00+00:00', $link->getValidUntil());
        $this->assertSame(100, $link->getMaxClicks());
        $this->assertNotNull($link->getClicks());
        $this->assertSame(42, $link->getClicks()->getTotal());
        $this->assertSame(37, $link->getClicks()->getNonBots());
        $this->assertCount(1, $link->getRules());
    }

    public function test_get_link_encodes_code_in_path(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/x',
                        'domain' => 'short.example.com',
                        'code' => 'x',
                        'rules' => [],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $client->getLink('Ab C/45');

        $this->assertSame('/api/v1/links/Ab%20C%2F45', $container[0]['request']->getUri()->getPath());
    }

    public function test_get_link_without_clicks_returns_null_summary(): void
    {
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/NoClicks',
                        'domain' => 'short.example.com',
                        'code' => 'NoClicks',
                        'rules' => [],
                    ],
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $this->assertNull($client->getLink('NoClicks')->getClicks());
    }

    public function test_get_link_rejects_degenerate_codes_before_request(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        foreach (['', '.', '..', '...'] as $code) {
            try {
                $client->getLink($code);
                $this->fail(sprintf('InvalidRequestException was expected for code "%s".', $code));
            } catch (InvalidRequestException $exception) {
                // expected
            }
        }

        $this->assertCount(0, (array) $container, 'No HTTP request should be sent for a degenerate code.');
    }

    public function test_update_link_rejects_degenerate_code_before_request(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->updateLink('..', (new UpdateLinkRequest)->setTitle('t'));
            $this->fail('InvalidRequestException was expected.');
        } catch (InvalidRequestException $exception) {
            $this->assertCount(0, (array) $container, 'No HTTP request should be sent for a degenerate code.');
        }
    }

    public function test_injected_client_without_http_errors_still_maps_problems(): void
    {
        $mock = new MockHandler(
            [
                new Response(422, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'validation-error',
                    'title' => 'The request failed validation.',
                    'status' => 422,
                    'errors' => ['rules' => ['The rules field is required.']],
                ])),
            ]
        );
        $httpClient = new Client([
            'handler' => HandlerStack::create($mock),
            'base_uri' => 'https://api.example.com',
            'http_errors' => false,
        ]);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertSame('validation-error', $exception->getProblemType());
            $this->assertArrayHasKey('rules', $exception->getErrors());
        }
    }

    public function test_injected_client_without_http_errors_retries_server_errors(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(500, [], ''),
                new Response(200, [], (string) json_encode([
                    'data' => [
                        ['domain' => 'go.example.com', 'url' => 'https://go.example.com', 'is_default' => false],
                    ],
                ])),
            ]
        );
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.example.com',
            'http_errors' => false,
        ]);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 1], $httpClient);

        $domains = $client->listDomains();

        $this->assertCount(1, $domains);
        $this->assertCount(2, (array) $container, 'The 500 response should be retried once.');
    }

    public function test_negative_retries_config_behaves_as_zero(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode(['data' => []])),
            ]
        );
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => -1], $httpClient);

        $this->assertSame([], $client->listDomains());
        $this->assertCount(1, (array) $container, 'A negative retries config must still perform the request once.');
    }

    public function test_get_link_tolerates_out_of_contract_variant_weight(): void
    {
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/x',
                        'domain' => 'short.example.com',
                        'code' => 'x',
                        'rules' => [
                            [
                                'url' => 'https://example.com/c',
                                'conditions' => [],
                                'variants' => [
                                    ['url' => 'https://example.com/a', 'weight' => 5000, 'label' => 'A'],
                                    ['url' => 'https://example.com/b', 'weight' => 1],
                                ],
                                'transition_mode' => null,
                            ],
                        ],
                    ],
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $link = $client->getLink('x');

        $this->assertCount(2, $link->getRules()[0]->getVariants());
        $this->assertSame(5000, $link->getRules()[0]->getVariants()[0]->getWeight());
    }

    public function test_get_link_throws_not_found_for_foreign_code(): void
    {
        $mock = new MockHandler(
            [
                new Response(404, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'not-found',
                    'title' => 'Not Found.',
                    'status' => 404,
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $this->expectException(NotFoundException::class);
        $client->getLink('F0reign1');
    }

    public function test_update_link_sends_explicit_nulls_and_omits_untouched_fields(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/AbC12345',
                        'domain' => 'short.example.com',
                        'code' => 'AbC12345',
                        'valid_until' => '2026-12-31T23:59:59+00:00',
                        'max_clicks' => null,
                        'rules' => [
                            ['url' => 'https://example.com/landing', 'conditions' => [], 'condition' => null, 'variants' => [], 'transition_mode' => null],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $patch = (new UpdateLinkRequest)
            ->setMaxClicks(null)
            ->setValidUntil(new \DateTimeImmutable('2026-12-31T23:59:59+00:00'));

        $link = $client->updateLink('AbC12345', $patch);

        $request = $container[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('/api/v1/links/AbC12345', $request->getUri()->getPath());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertTrue(array_key_exists('max_clicks', $body), 'An explicit null must be serialized.');
        $this->assertNull($body['max_clicks']);
        $this->assertSame('2026-12-31T23:59:59+00:00', $body['valid_until']);
        $this->assertArrayNotHasKey('title', $body);
        $this->assertArrayNotHasKey('rules', $body);
        $this->assertArrayNotHasKey('valid_since', $body);

        $this->assertSame('2026-12-31T23:59:59+00:00', $link->getValidUntil());
        $this->assertNull($link->getMaxClicks());
    }

    /**
     * Contract §5.2 example payload, matched 1:1.
     */
    public function test_contract_fixture_patch_payload(): void
    {
        $patch = (new UpdateLinkRequest)
            ->setMaxClicks(null)
            ->setValidUntil(new \DateTimeImmutable('2026-12-31T23:59:59+00:00'));

        $this->assertSame(
            ['max_clicks' => null, 'valid_until' => '2026-12-31T23:59:59+00:00'],
            $patch->toArray()
        );
    }

    public function test_update_link_replaces_rules_wholesale(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/AbC12345',
                        'domain' => 'short.example.com',
                        'code' => 'AbC12345',
                        'rules' => [
                            ['url' => 'https://example.com/sale', 'conditions' => [], 'condition' => null, 'variants' => [], 'transition_mode' => null],
                            ['url' => 'https://example.com/home', 'conditions' => [], 'condition' => null, 'variants' => [], 'transition_mode' => null],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $patch = (new UpdateLinkRequest)->setRules([
            new CreateLinkRule('https://example.com/sale', [
                new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']),
            ]),
            new CreateLinkRule('https://example.com/home'),
        ]);

        $link = $client->updateLink('AbC12345', $patch);

        $body = json_decode((string) $container[0]['request']->getBody(), true);
        $this->assertCount(2, $body['rules']);
        $this->assertSame('time_before', $body['rules'][0]['conditions'][0]['type']);
        $this->assertSame(['url' => 'https://example.com/home'], $body['rules'][1]);
        $this->assertCount(2, $link->getRules());
    }

    public function test_update_link_rejects_empty_patch_before_request(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->updateLink('AbC12345', new UpdateLinkRequest);
            $this->fail('InvalidRequestException was expected.');
        } catch (InvalidRequestException $exception) {
            $this->assertCount(0, (array) $container, 'No HTTP request should be sent for an empty patch.');
        }
    }

    public function test_update_link_request_serializes_cleared_title_and_window(): void
    {
        $patch = (new UpdateLinkRequest)
            ->setTitle(null)
            ->setValidSince(null);

        $this->assertSame(['title' => null, 'valid_since' => null], $patch->toArray());
        $this->assertFalse($patch->isEmpty());
    }

    public function test_update_link_request_rejects_non_positive_max_clicks(): void
    {
        $this->expectException(InvalidRequestException::class);

        (new UpdateLinkRequest)->setMaxClicks(0);
    }

    public function test_update_link_request_rejects_empty_rules_replacement(): void
    {
        $this->expectException(InvalidRequestException::class);

        (new UpdateLinkRequest)->setRules([]);
    }

    public function test_create_link_retries_and_throws_transport_exception(): void
    {
        $mock = new MockHandler(
            [
                new ConnectException('Network down', new Request('POST', '/api/v1/links')),
                new ConnectException('Network down', new Request('POST', '/api/v1/links')),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 1], $httpClient);

        $this->expectException(TransportException::class);
        $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
    }

    public function test_create_link_with_domain_strategy_serializes_request(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(201, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://go.example.com/Str4tegy',
                        'domain' => 'go.example.com',
                        'code' => 'Str4tegy',
                        'title' => null,
                        'forward_query' => false,
                        'callback_data' => null,
                        'rules' => [
                            ['url' => 'https://example.com/landing', 'condition' => null, 'transition_mode' => null],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $response = $client->createLink(new CreateLinkRequest(
            null,
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com/landing')],
            'round_robin',
            'campaigns'
        ));

        $this->assertSame('go.example.com', $response->getDomain());

        $body = json_decode((string) $container[0]['request']->getBody(), true);
        $this->assertSame('round_robin', $body['domain_strategy']);
        $this->assertSame('campaigns', $body['domain_group']);
        $this->assertArrayNotHasKey('domain', $body);
    }

    public function test_create_simple_link_with_domain_strategy_serializes_request(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(201, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://go.example.com/R4ndom00',
                        'domain' => 'go.example.com',
                        'code' => 'R4ndom00',
                        'title' => null,
                        'forward_query' => false,
                        'callback_data' => null,
                        'rules' => [
                            ['url' => 'https://example.com/landing', 'condition' => null, 'transition_mode' => null],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $response = $client->createSimpleLink(
            'https://example.com/landing',
            null,
            null,
            null,
            null,
            null,
            'random'
        );

        $this->assertSame('R4ndom00', $response->getCode());

        $body = json_decode((string) $container[0]['request']->getBody(), true);
        $this->assertSame('random', $body['domain_strategy']);
        $this->assertArrayNotHasKey('domain', $body);
        $this->assertArrayNotHasKey('domain_group', $body);
    }

    public function test_list_domains_success(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        ['domain' => 'go.example.com', 'url' => 'https://go.example.com', 'is_default' => false],
                        ['domain' => 'short.example.com', 'url' => 'https://short.example.com', 'is_default' => true],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $domains = $client->listDomains();

        $this->assertCount(2, $domains);
        $this->assertSame('go.example.com', $domains[0]->getDomain());
        $this->assertSame('https://go.example.com', $domains[0]->getUrl());
        $this->assertFalse($domains[0]->isDefault());
        $this->assertTrue($domains[1]->isDefault());

        $request = $container[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/domains', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame('Bearer token', $request->getHeaderLine('Authorization'));
    }

    public function test_list_domains_with_group_adds_query(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        ['domain' => 'go.example.com', 'url' => 'https://go.example.com', 'is_default' => false],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $domains = $client->listDomains('campaigns');

        $this->assertCount(1, $domains);
        $this->assertSame('group=campaigns', $container[0]['request']->getUri()->getQuery());
    }

    public function test_list_domains_throws_validation_exception_for_unknown_group(): void
    {
        $mock = new MockHandler(
            [
                new Response(422, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                    'type' => 'validation-error',
                    'title' => 'The request failed validation.',
                    'status' => 422,
                    'detail' => 'The selected group is invalid.',
                    'errors' => ['group' => ['The selected group is invalid.']],
                ])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->listDomains('does-not-exist');
            $this->fail('ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('group', $exception->getErrors());
        }
    }

    public function test_list_domain_groups_success(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode([
                    'data' => [
                        ['code' => 'campaigns', 'name' => 'Campaigns', 'domains_count' => 5],
                        ['code' => 'primary', 'name' => 'Primary', 'domains_count' => 3],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $groups = $client->listDomainGroups();

        $this->assertCount(2, $groups);
        $this->assertSame('campaigns', $groups[0]->getCode());
        $this->assertSame('Campaigns', $groups[0]->getName());
        $this->assertSame(5, $groups[0]->getDomainsCount());

        $request = $container[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/domain-groups', $request->getUri()->getPath());
    }

    public function test_list_domains_throws_api_exception_on_malformed_body(): void
    {
        $mock = new MockHandler(
            [
                new Response(200, [], (string) json_encode(['unexpected' => true])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $this->expectException(ApiException::class);
        $client->listDomains();
    }

    public function test_create_link_request_rejects_domain_with_strategy(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkRequest(
            'short.example.com',
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com/landing')],
            'random'
        );
    }

    public function test_create_link_request_rejects_group_without_strategy(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkRequest(
            null,
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com/landing')],
            null,
            'campaigns'
        );
    }

    public function test_create_link_request_allows_strategy_with_group(): void
    {
        $request = new CreateLinkRequest(
            null,
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com/landing')],
            'coldest',
            'campaigns'
        );

        $body = $request->toArray();
        $this->assertSame('coldest', $body['domain_strategy']);
        $this->assertSame('campaigns', $body['domain_group']);
        $this->assertArrayNotHasKey('domain', $body);
    }

    public function test_create_link_serializes_activity_window_and_click_budget(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(201, [], (string) json_encode([
                    'data' => [
                        'url' => 'https://short.example.com/W1ndowed',
                        'domain' => 'short.example.com',
                        'code' => 'W1ndowed',
                        'title' => null,
                        'forward_query' => false,
                        'callback_data' => null,
                        'valid_since' => '2026-08-01T00:00:00+00:00',
                        'valid_until' => '2026-09-01T00:00:00+00:00',
                        'max_clicks' => 100,
                        'rules' => [
                            ['url' => 'https://example.com/landing', 'condition' => null, 'transition_mode' => null],
                        ],
                    ],
                ])),
            ]
        );

        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        $response = $client->createLink(new CreateLinkRequest(
            null,
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com/landing')],
            null,
            null,
            new \DateTimeImmutable('2026-08-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T03:00:00+03:00'),
            100
        ));

        $body = json_decode((string) $container[0]['request']->getBody(), true);
        $this->assertSame('2026-08-01T00:00:00+00:00', $body['valid_since']);
        $this->assertSame('2026-09-01T03:00:00+03:00', $body['valid_until']);
        $this->assertSame(100, $body['max_clicks']);

        $this->assertSame('2026-08-01T00:00:00+00:00', $response->getValidSince());
        $this->assertSame('2026-09-01T00:00:00+00:00', $response->getValidUntil());
        $this->assertSame(100, $response->getMaxClicks());
    }

    public function test_create_link_request_omits_unset_activity_fields(): void
    {
        $request = new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]);

        $body = $request->toArray();
        $this->assertArrayNotHasKey('valid_since', $body);
        $this->assertArrayNotHasKey('valid_until', $body);
        $this->assertArrayNotHasKey('max_clicks', $body);
    }

    public function test_create_link_response_returns_null_activity_fields_when_absent(): void
    {
        $response = CreateLinkResponse::fromArray([
            'url' => 'https://short.example.com/NoLimits',
            'domain' => 'short.example.com',
            'code' => 'NoLimits',
            'rules' => [],
        ]);

        $this->assertNull($response->getValidSince());
        $this->assertNull($response->getValidUntil());
        $this->assertNull($response->getMaxClicks());
    }

    public function test_create_link_rule_serializes_multiple_conditions(): void
    {
        $rule = new CreateLinkRule('https://example.com/landing', [
            new CreateLinkCondition('device', ['device' => 'mobile']),
            new CreateLinkCondition('language', ['language' => 'en', 'country' => 'US']),
        ]);

        $body = $rule->toArray();
        $this->assertCount(2, $body['conditions']);
        $this->assertSame('device', $body['conditions'][0]['type']);
        $this->assertSame('language', $body['conditions'][1]['type']);
        $this->assertArrayNotHasKey('condition', $body);
    }

    public function test_create_link_rule_rejects_more_than_ten_conditions(): void
    {
        $conditions = [];
        for ($i = 0; $i < 11; $i++) {
            $conditions[] = new CreateLinkCondition('query_param', ['key' => 'p'.$i, 'value' => 'v']);
        }

        $this->expectException(InvalidRequestException::class);
        new CreateLinkRule('https://example.com/landing', $conditions);
    }

    public function test_response_rule_parses_conditions_and_ignores_legacy_condition(): void
    {
        $rule = CreateLinkResponseRule::fromArray([
            'url' => 'https://example.com/landing',
            'conditions' => [
                ['type' => 'after_date', 'data' => ['after' => '2026-03-05T10:00:00+00:00']],
                ['type' => 'ip_address', 'data' => ['ip' => '10.0.0.0/24']],
            ],
            'condition' => ['type' => 'after_date', 'data' => ['after' => '2026-03-05T10:00:00+00:00']],
            'variants' => [],
            'transition_mode' => null,
        ]);

        $this->assertCount(2, $rule->getConditions());
        $this->assertSame('after_date', $rule->getConditions()[0]->getType());
        $this->assertSame('ip_address', $rule->getConditions()[1]->getType());
    }

    /**
     * Contract §7.1 example: an A/B split rule serialized 1:1.
     */
    public function test_create_link_rule_serializes_variants(): void
    {
        $rule = new CreateLinkRule('https://example.com/control', [], null, [
            new CreateLinkVariant('https://example.com/a', 1, 'A'),
            new CreateLinkVariant('https://example.com/b', 3, 'B'),
        ]);

        $this->assertSame([
            'url' => 'https://example.com/control',
            'variants' => [
                ['url' => 'https://example.com/a', 'weight' => 1, 'label' => 'A'],
                ['url' => 'https://example.com/b', 'weight' => 3, 'label' => 'B'],
            ],
        ], $rule->toArray());
    }

    public function test_create_link_rule_omits_variants_key_without_split(): void
    {
        $rule = new CreateLinkRule('https://example.com/landing');

        $this->assertArrayNotHasKey('variants', $rule->toArray());
        $this->assertSame([], $rule->getVariants());
    }

    public function test_create_link_rule_rejects_single_variant(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkRule('https://example.com/control', [], null, [
            new CreateLinkVariant('https://example.com/a', 1),
        ]);
    }

    public function test_create_link_rule_rejects_more_than_twenty_variants(): void
    {
        $variants = [];
        for ($i = 0; $i < 21; $i++) {
            $variants[] = new CreateLinkVariant('https://example.com/v'.$i, 1);
        }

        $this->expectException(InvalidRequestException::class);
        new CreateLinkRule('https://example.com/control', [], null, $variants);
    }

    public function test_variant_rejects_zero_weight(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkVariant('https://example.com/a', 0);
    }

    public function test_variant_rejects_weight_above_maximum(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkVariant('https://example.com/a', 1001);
    }

    public function test_variant_label_length_is_counted_in_characters(): void
    {
        $multibyte = new CreateLinkVariant('https://example.com/a', 1, str_repeat('ю', 64));
        $this->assertSame(64, mb_strlen((string) $multibyte->getLabel()));

        $this->expectException(InvalidRequestException::class);
        new CreateLinkVariant('https://example.com/a', 1, str_repeat('a', 65));
    }

    public function test_response_rule_parses_variants(): void
    {
        $rule = CreateLinkResponseRule::fromArray([
            'url' => 'https://example.com/control',
            'conditions' => [],
            'condition' => null,
            'variants' => [
                ['url' => 'https://example.com/a', 'weight' => 1, 'label' => 'A'],
                ['url' => 'https://example.com/b', 'weight' => 3, 'label' => null],
            ],
            'transition_mode' => null,
        ]);

        $this->assertCount(2, $rule->getVariants());
        $this->assertSame('https://example.com/a', $rule->getVariants()[0]->getUrl());
        $this->assertSame('A', $rule->getVariants()[0]->getLabel());
        $this->assertSame(3, $rule->getVariants()[1]->getWeight());
        $this->assertNull($rule->getVariants()[1]->getLabel());
    }

    /**
     * Contract §16 fixture: the valid `time_before` payload. The SDK emits the
     * modern `conditions` list, into which the fixture's deprecated single
     * `condition` folds as element 0 (§5).
     */
    public function test_contract_fixture_valid_time_before_payload(): void
    {
        $request = new CreateLinkRequest(null, null, null, null, [
            new CreateLinkRule('https://example.com/redirect', [
                new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']),
            ]),
        ]);

        $this->assertSame([
            'rules' => [
                [
                    'url' => 'https://example.com/redirect',
                    'conditions' => [
                        [
                            'type' => 'time_before',
                            'data' => ['before' => '2026-03-05T10:00:00+00:00'],
                        ],
                    ],
                ],
            ],
        ], $request->toArray());
    }

    /**
     * Contract §16 fixture: the valid delayed-transition payload, matched 1:1.
     */
    public function test_contract_fixture_valid_delayed_transition_payload(): void
    {
        $request = new CreateLinkRequest(null, null, null, null, [
            new CreateLinkRule('https://example.com/redirect', [], 'delayed'),
        ]);

        $this->assertSame([
            'rules' => [
                ['url' => 'https://example.com/redirect', 'transition_mode' => 'delayed'],
            ],
        ], $request->toArray());
    }

    public function test_boundary_values_are_accepted(): void
    {
        $conditions = [];
        for ($i = 0; $i < CreateLinkRule::MAX_CONDITIONS; $i++) {
            $conditions[] = new CreateLinkCondition('query_param', ['key' => 'p'.$i, 'value' => 'v']);
        }
        $rule = new CreateLinkRule('https://example.com', $conditions);
        $this->assertCount(10, $rule->getConditions());

        $variants = [];
        for ($i = 0; $i < CreateLinkRule::MAX_VARIANTS; $i++) {
            $variants[] = new CreateLinkVariant('https://example.com/v'.$i, 1);
        }
        $this->assertCount(20, (new CreateLinkRule('https://example.com', [], null, $variants))->getVariants());
        $this->assertSame(1000, (new CreateLinkVariant('https://example.com/w', 1000))->getWeight());

        $rules = [];
        for ($i = 0; $i < CreateLinkRequest::MAX_RULES; $i++) {
            $rules[] = new CreateLinkRule('https://example.com/r'.$i);
        }
        $moment = new \DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $request = new CreateLinkRequest(null, null, null, null, $rules, null, null, $moment, $moment, 1);
        $this->assertCount(50, $request->getRules());
        $this->assertSame(1, $request->getMaxClicks());
    }

    public function test_create_link_request_rejects_empty_rules(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkRequest(null, null, null, null, []);
    }

    public function test_create_link_request_rejects_more_than_fifty_rules(): void
    {
        $rules = [];
        for ($i = 0; $i < 51; $i++) {
            $rules[] = new CreateLinkRule('https://example.com/r'.$i);
        }

        $this->expectException(InvalidRequestException::class);
        new CreateLinkRequest(null, null, null, null, $rules);
    }

    public function test_title_length_is_validated_in_characters(): void
    {
        $rules = [new CreateLinkRule('https://example.com')];

        $ok = new CreateLinkRequest(null, str_repeat('ю', 64), null, null, $rules);
        $this->assertSame(64, mb_strlen((string) $ok->getTitle()));

        (new UpdateLinkRequest)->setTitle(str_repeat('ю', 64));

        try {
            new CreateLinkRequest(null, str_repeat('a', 65), null, null, $rules);
            $this->fail('InvalidRequestException was expected for a 65-char title.');
        } catch (InvalidRequestException $exception) {
            // expected
        }

        $this->expectException(InvalidRequestException::class);
        (new UpdateLinkRequest)->setTitle(str_repeat('a', 65));
    }

    public function test_create_link_request_rejects_non_positive_max_clicks(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkRequest(
            null,
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com')],
            null,
            null,
            null,
            null,
            0
        );
    }

    public function test_create_link_request_rejects_inverted_activity_window(): void
    {
        $this->expectException(InvalidRequestException::class);

        new CreateLinkRequest(
            null,
            null,
            null,
            null,
            [new CreateLinkRule('https://example.com')],
            null,
            null,
            new \DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-01T00:00:00+00:00')
        );
    }

    public function test_create_simple_link_rejects_domain_with_strategy_before_request(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler([]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $httpClient = new Client(['handler' => $stack, 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createSimpleLink(
                'https://example.com/landing',
                'short.example.com',
                null,
                null,
                null,
                null,
                'random'
            );
            $this->fail('InvalidRequestException was expected.');
        } catch (InvalidRequestException $exception) {
            $this->assertCount(0, (array) $container, 'No HTTP request should be sent for an invalid request.');
        }
    }
}
