# Brevity PHP SDK

PHP client for the [Brevity](https://github.com/vaslv/brevity) short-link engine API.

**Русская версия: [README.ru.md](./README.ru.md)**

[![Packagist Version](https://img.shields.io/packagist/v/vaslv/brevity-php-sdk)](https://packagist.org/packages/vaslv/brevity-php-sdk)
[![CI](https://github.com/vaslv/brevity-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/vaslv/brevity-php-sdk/actions/workflows/ci.yml)
[![Plumb score](https://plumbphp.dev/badges/vaslv/brevity-php-sdk/composite.svg)](https://plumbphp.dev/vaslv/brevity-php-sdk)

## Features

- Full `/api/v1` surface: create links (`POST /links`), read link state with a
  click summary (`GET /links/{code}`), partial updates (`PATCH /links/{code}`),
  domain and domain-group registries.
- Typed request/response DTOs: rules with up to 10 AND-ed conditions, A/B split
  variants with weights, activity window (`valid_since` / `valid_until`) and
  click budget (`max_clicks`).
- RFC 7807 error handling: exceptions are dispatched on the stable problem
  `type` code, never on texts or bare HTTP statuses.
- Contract-recommended transport behavior: retries only for network failures
  and 5xx, `Accept: application/json` everywhere, obvious mistakes rejected
  client-side before any HTTP round trip.
- Laravel bridge (5.8+): auto-discovered service provider, facade and
  publishable config.
- Runs on PHP 7.1+ with Guzzle 6.5 or 7.

## Requirements

- PHP >= 7.1 with `ext-json`
- `guzzlehttp/guzzle` ^6.5 || ^7.0
- optional: Laravel 5.8+ for the bridge

## Installation

```bash
composer require vaslv/brevity-php-sdk
```

## Quick start

```php
use Vaslv\Brevity\BrevityClient;

$client = new BrevityClient([
    'base_uri' => 'https://brevity.example.com', // the technical host, not a short-link domain
    'token' => getenv('BREVITY_TOKEN'),
]);

$link = $client->createSimpleLink('https://example.com/landing');
echo $link->getUrl(); // https://short.example.com/AbC12345
```

The API is served **only on the technical host** (the one behind `APP_URL`);
short-link domains answer 404 to any `/api/...` request. The token is issued
from the admin panel and must carry the `links:create` ability — newly issued
tokens also get `links:read` and `links:update`.

## Usage

### Conditions and A/B variants

```php
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;
use Vaslv\Brevity\DTO\CreateLinkVariant;

$request = new CreateLinkRequest(
    null,                            // domain (null → the default domain)
    'Spring campaign',               // title
    true,                            // forward_query
    ['click_id' => '{{click.id}}'],  // callback_data template
    [
        // Rules are tried in order; the first whose conditions all match wins.
        new CreateLinkRule('https://example.com/sale', [
            new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']),
            new CreateLinkCondition('device', ['device' => 'mobile']),
        ]),
        // A/B split: weights 1:3, sticky per visitor.
        new CreateLinkRule('https://example.com/control', [], null, [
            new CreateLinkVariant('https://example.com/a', 1, 'A'),
            new CreateLinkVariant('https://example.com/b', 3, 'B'),
        ]),
        // Unconditional fallback.
        new CreateLinkRule('https://example.com/home'),
    ]
);

$link = $client->createLink($request);
```

Condition types: `time_before`, `after_date`, `query_param`, `ip_address`,
`device`, `language` — see [API.md](./API.md) (§6) for the `data` shape of
each. Condition `data` passes through the SDK untouched.

### Activity window and click budget

```php
$request = new CreateLinkRequest(
    null, null, null, null,
    [new CreateLinkRule('https://example.com/landing')],
    null, null,
    new DateTimeImmutable('2026-08-01T00:00:00+00:00'), // valid_since
    new DateTimeImmutable('2026-09-01T00:00:00+00:00'), // valid_until (inclusive)
    100                                                 // max_clicks (bots count too)
);
```

Outside the window, or once the budget is exhausted, the link answers 404.

### Domain selection

```php
// Explicit domain (must exist in the registry):
$client->createSimpleLink('https://example.com/x', 'go.example.com');

// Auto-picked domain: 'random' / 'round_robin' / 'coldest', optionally within a group:
$request = new CreateLinkRequest(
    null, null, null, null,
    [new CreateLinkRule('https://example.com/x')],
    'round_robin',
    'campaigns'
);

// Registries:
$domains = $client->listDomains();     // Domain[]; or listDomains('campaigns')
$groups = $client->listDomainGroups(); // DomainGroup[]
```

### Reading a link

```php
$link = $client->getLink('AbC12345');  // needs the links:read ability

$link->getClicks()->getTotal();        // all clicks, bots included
$link->getClicks()->getNonBots();      // may lag behind reality by seconds
```

### Updating a link

```php
use Vaslv\Brevity\DTO\UpdateLinkRequest;

$patch = (new UpdateLinkRequest)
    ->setValidUntil(new DateTimeImmutable('2026-12-31T23:59:59+00:00'))
    ->setMaxClicks(null);              // an explicit null clears the budget

$client->updateLink('AbC12345', $patch); // needs the links:update ability
```

Untouched fields keep their server-side values; `setRules()` replaces the
whole rule list. `code` and `domain` cannot be changed.

## Error handling

Every `/api/v1` error is an RFC 7807 problem; the SDK dispatches on the stable
`type` code:

| `type` | HTTP | Exception |
|---|---|---|
| `unauthenticated` | 401 | `AuthenticationException` |
| `missing-ability` | 403 | `MissingAbilityException` |
| `forbidden` | 403 | `ForbiddenException` |
| `not-found` | 404 | `NotFoundException` |
| `validation-error` | 422 | `ValidationException` (`getErrors()`) |
| `too-many-requests` | 429 | `RateLimitException` (`getRetryAfter()`) |
| `http-error`, `server-error` | other | `ApiException` |

An unknown `type` (a proxy answering instead of the API, a future contract
code) falls back to the HTTP-status mapping; the raw code stays available
via `getProblemType()`.

All of the above extend `ApiException` (`getStatusCode()`, `getResponseBody()`,
`getProblemType()`); `MissingAbilityException` extends `ForbiddenException`.
Network/timeout failures throw `TransportException`; client-side misuse
(contradictory options, an empty patch) throws `InvalidRequestException`
before any HTTP round trip.

```php
use Vaslv\Brevity\Exceptions\ApiException;
use Vaslv\Brevity\Exceptions\RateLimitException;
use Vaslv\Brevity\Exceptions\TransportException;
use Vaslv\Brevity\Exceptions\ValidationException;

try {
    $link = $client->createLink($request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();    // field => messages[]
} catch (RateLimitException $e) {
    $wait = $e->getRetryAfter();  // seconds, or null
} catch (ApiException $e) {
    $type = $e->getProblemType(); // stable machine code, or null
} catch (TransportException $e) {
    // network failure after the configured retries
}
```

Rate limits: two independent budgets — reads and writes — of 120 requests per
minute per service.

## Laravel

The service provider and the `Brevity` facade are auto-discovered on
Laravel 5.8+. Configure via `.env`:

```dotenv
BREVITY_BASE_URI=https://brevity.example.com
BREVITY_TOKEN=your-token
BREVITY_TIMEOUT=7
BREVITY_CONNECT_TIMEOUT=5
BREVITY_RETRIES=1
```

```php
$link = Brevity::createSimpleLink('https://example.com/landing');
```

Publish the config when you need to tweak it:
`php artisan vendor:publish --tag=brevity-config`.

## Testing

No local PHP toolchain needed — the suite runs in Docker:

```bash
docker compose run --rm tests                       # composer install + phpunit
docker compose run --rm tests phpstan analyse       # static analysis (level 8)
docker compose run --rm tests vendor/bin/pint --test # code style
```

## Documentation

The full API contract lives in [API.md](./API.md) — a copy of the canonical
English `docs/03-api.md` of the [main repository](https://github.com/vaslv/brevity),
where a Russian mirror is kept under `docs/ru/`.

## License

[MIT](./LICENSE)
