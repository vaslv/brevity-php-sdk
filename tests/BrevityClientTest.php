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
use Vaslv\Brevity\DTO\CreateLinkRule;
use Vaslv\Brevity\Exceptions\AuthenticationException;
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
                new Response(201, [], json_encode([
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
        $this->assertSame('direct', $body['rules'][0]['transition_mode']);
    }

    public function test_create_link_success(): void
    {
        $container = [];
        $history = Middleware::history($container);
        $mock = new MockHandler(
            [
                new Response(201, [], json_encode([
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
                                'condition' => [
                                    'type' => 'time_before',
                                    'data' => ['before' => '2026-03-05T10:00:00+00:00'],
                                ],
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
                    new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']),
                    'delayed'
                ),
            ]
        );

        $response = $client->createLink($request);

        $this->assertSame('AbC12345', $response->getCode());
        $this->assertSame('short.example.com', $response->getDomain());
        $this->assertCount(1, $response->getRules());
        $this->assertSame('delayed', $response->getRules()[0]->getTransitionMode());
        $this->assertCount(1, $container);

        $lastRequest = $container[0]['request'];
        $this->assertSame('Bearer test-token', $lastRequest->getHeaderLine('Authorization'));
        $body = json_decode((string) $lastRequest->getBody(), true);
        $this->assertSame('short.example.com', $body['domain']);
        $this->assertSame('time_before', $body['rules'][0]['condition']['type']);
    }

    public function test_create_link_throws_authentication_exception(): void
    {
        $mock = new MockHandler(
            [
                new Response(401, [], json_encode(['message' => 'Unauthenticated.'])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'bad-token', 'retries' => 0], $httpClient);

        $this->expectException(AuthenticationException::class);
        $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
    }

    public function test_create_link_throws_validation_exception_with_errors(): void
    {
        $mock = new MockHandler(
            [
                new Response(422, [], json_encode([
                    'message' => 'The given data was invalid.',
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
            $errors = $exception->getErrors();
            $this->assertArrayHasKey('rules.0.condition.data.before', $errors);
        }
    }

    public function test_create_link_throws_rate_limit_exception_with_retry_after(): void
    {
        $mock = new MockHandler(
            [
                new Response(429, ['Retry-After' => '30'], json_encode(['message' => 'Too Many Requests'])),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 0], $httpClient);

        try {
            $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
            $this->fail('RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertSame(30, $exception->getRetryAfter());
        }
    }

    public function test_create_link_returns_null_domain_when_response_domain_is_null(): void
    {
        $mock = new MockHandler(
            [
                new Response(201, [], json_encode([
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

    public function test_create_link_retries_and_throws_transport_exception(): void
    {
        $mock = new MockHandler(
            [
                new ConnectException('Network down', new Request('POST', '/api/links')),
                new ConnectException('Network down', new Request('POST', '/api/links')),
            ]
        );
        $httpClient = new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://api.example.com']);
        $client = new BrevityClient(['base_uri' => 'https://api.example.com', 'token' => 'token', 'retries' => 1], $httpClient);

        $this->expectException(TransportException::class);
        $client->createLink(new CreateLinkRequest(null, null, null, null, [new CreateLinkRule('https://example.com')]));
    }
}
