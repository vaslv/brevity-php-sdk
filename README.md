# Brevity PHP SDK

PHP SDK for the **Brevity** short-links API — create short links with
priority-based transition rules, time conditions, transition modes and
outgoing click callbacks, from plain PHP or Laravel.

> 🇷🇺 Документация на русском — [ниже](#brevity-php-sdk-русский).
> 📄 Полный контракт API (эндпоинты, поля, валидация) — в [API.md](./API.md).

---

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start (plain PHP)](#quick-start-plain-php)
- [Configuration](#configuration)
- [Laravel integration](#laravel-integration)
- [Conditions](#conditions)
- [Transition modes](#transition-modes)
- [Domains](#domains)
- [Error handling](#error-handling)
- [Rate limiting, retries & timeouts](#rate-limiting-retries--timeouts)
- [API reference](#api-reference)
- [Testing](#testing)
- [License](#license)

---

## Features

- Create a short link via `POST /api/links` with one call.
- One-liner helper `createSimpleLink()` for the common single-URL case.
- Automatic domain selection by strategy (`random` / `round_robin` / `coldest`),
  optionally scoped to a domain group.
- Browse the domain registry via `listDomains()` / `listDomainGroups()`.
- Fail-fast client-side validation of contradictory domain options (no wasted round-trip).
- Strongly-typed request/response DTOs (no associative-array guessing).
- Typed exceptions for every documented HTTP outcome:
  - `AuthenticationException` (401)
  - `ValidationException` (422, exposes per-field errors)
  - `RateLimitException` (429, exposes `Retry-After`)
  - `ApiException` (other 4xx/5xx)
  - `TransportException` (network/timeout)
- Automatic retries for network failures and `5xx` (never for `4xx`).
- First-class Laravel support: auto-discovered Service Provider + `Brevity` Facade.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `>= 7.1` |
| ext-json | `*` |
| `guzzlehttp/guzzle` | `^6.5` or `^7.0` |
| Laravel (optional) | `5.8` – `13.x` |

## Installation

```bash
composer require vaslv/brevity-php-sdk
```

You will also need, from the Brevity admin panel:

1. A **Service** that owns the links and receives callbacks.
2. An **API token** of that service with the `links:create` ability.
3. The **technical host** base URL (the same host as `APP_URL`, e.g.
   `https://brevity.example.com`) — short-link domains only serve
   redirects and return `404` for `/api/...`.

See [API.md §1–§3](./API.md) for how to obtain these.

## Quick start (plain PHP)

The simplest case — one destination URL, no conditions:

```php
<?php

use Vaslv\Brevity\BrevityClient;

$client = new BrevityClient([
    'base_uri' => 'https://brevity.example.com',
    'token'    => 'your-sanctum-token',
]);

$response = $client->createSimpleLink('https://example.com/landing');

echo $response->getUrl();  // https://short.example.com/AbC12345
```

`createSimpleLink()` accepts optional `domain`, `title`, `forwardQuery`,
`callbackData` and `transitionMode`:

```php
$response = $client->createSimpleLink(
    'https://example.com/landing',
    'short.example.com',           // domain (must exist in the registry)
    'Spring campaign',             // title
    true,                          // forward_query
    ['campaign_id' => 'cmp-42'],   // callback_data
    'delayed'                      // transition_mode
);
```

For full control — multiple rules in priority order, conditions and
transition modes — build a `CreateLinkRequest`:

```php
<?php

use Vaslv\Brevity\BrevityClient;
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;

$client = new BrevityClient([
    'base_uri'        => 'https://brevity.example.com',
    'token'           => 'your-sanctum-token',
    'timeout'         => 7,
    'connect_timeout' => 5,
    'retries'         => 1,
]);

$request = new CreateLinkRequest(
    'short.example.com',             // domain
    'Spring campaign',               // title
    true,                            // forward_query
    ['campaign_id' => 'cmp-42'],     // callback_data
    [
        // Highest priority first. The server applies the first rule whose
        // condition is true; a rule without a condition is always true.
        new CreateLinkRule(
            'https://example.com/sale',
            new CreateLinkCondition('time_before', [
                'before' => '2026-03-05T10:00:00+00:00',
            ]),
            'delayed'
        ),
        // Fallback — no condition, so it matches once the sale window closes.
        new CreateLinkRule('https://example.com/home'),
    ]
);

$response = $client->createLink($request);

echo $response->getUrl();                          // short URL
echo $response->getCode();                         // AbC12345
echo $response->getRules()[0]->getTransitionMode(); // delayed
```

## Configuration

`BrevityClient` is constructed from a plain config array:

| Key | Type | Default | Description |
|---|---|---|---|
| `base_uri` | `string` | `''` | Base URL of the **technical host** (not a short-link domain). |
| `token` | `string` | `''` | Sanctum token with the `links:create` ability. |
| `timeout` | `float` | `7.0` | Total request timeout, seconds. |
| `connect_timeout` | `float` | `5.0` | Connection timeout, seconds. |
| `retries` | `int` | `1` | Retries for network/`5xx` failures. Total attempts = `retries + 1`. |

You may inject your own Guzzle client (useful for tests/middleware) as the
second constructor argument:

```php
$client = new BrevityClient($config, $myGuzzleClient);
```

## Laravel integration

The Service Provider and Facade are auto-discovered (Laravel 5.8+). Manual
registration, if you disabled discovery:

- Provider: `Vaslv\Brevity\Laravel\BrevityServiceProvider::class`
- Alias: `Brevity` → `Vaslv\Brevity\Laravel\BrevityFacade::class`

Publish the config file:

```bash
php artisan vendor:publish --tag=brevity-config
```

Configure via `.env`:

```dotenv
BREVITY_BASE_URI=https://brevity.example.com
BREVITY_TOKEN=your-sanctum-token
BREVITY_TIMEOUT=7
BREVITY_CONNECT_TIMEOUT=5
BREVITY_RETRIES=1
```

Use the Facade (or resolve `BrevityClient` from the container):

```php
use Brevity;

$response = Brevity::createSimpleLink('https://example.com', null, 'My link');
```

```php
use Vaslv\Brevity\BrevityClient;

public function __construct(private BrevityClient $brevity) {}
```

## Conditions

A condition makes a rule selective: it applies only while the condition is
true; otherwise the server tries the next rule by priority. A rule **without**
a condition is always true — put it last as a fallback.

| `type` | Purpose | `data` |
|---|---|---|
| `time_before` | Active while the current time is **before** the given moment | `{ "before": "<ISO 8601>" }` |

`before` uses the format `Y-m-d\TH:i:sP`, e.g. `2026-03-05T10:00:00+00:00`,
and is required for `time_before`.

```php
new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']);
```

The set of condition types is extensible server-side; pass `condition.data`
through unchanged — the SDK never transforms it. See [API.md §6](./API.md).

## Transition modes

How the server responds to a visitor when a rule matches:

| Value | Behavior |
|---|---|
| `direct` (or `null`) | HTTP 302 redirect. Default. |
| `delayed` | HTML page that auto-redirects after a countdown (5 s by default). |
| `manual` | HTML page with a "continue" button. |

When omitted, the response field is `null`, which is equivalent to `direct`.

## Domains

The server resolves a link's domain in this order: an explicit `domain` →
a `domain_strategy` → the registry's default domain → no domain (resolved via
`APP_URL`). An explicit `domain` and a `domain_strategy` are **mutually
exclusive**.

### Auto-pick by strategy

To get a link on a non-default domain without naming one, pass a strategy. The
pool is the whole registry, or a single group when `domainGroup` (a group code)
is given.

| Strategy | Picks |
|---|---|
| `random` | A random domain from the pool. |
| `round_robin` | The least-recently-used domain — each new link gets the next one. |
| `coldest` | The "coldest" domain — fewest links over the last 30 days. |

```php
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;

// Full request: 6th/7th constructor args are $domainStrategy and $domainGroup.
$request = new CreateLinkRequest(
    null,                                       // domain — must stay null with a strategy
    null, null, null,                           // title, forward_query, callback_data
    [new CreateLinkRule('https://example.com/landing')],
    'round_robin',                              // domain_strategy
    'campaigns'                                 // domain_group — group code (optional)
);
$response = $client->createLink($request);

// One-liner: domainStrategy / domainGroup are the 7th/8th arguments.
$response = $client->createSimpleLink(
    'https://example.com/landing',
    null, null, null, null, null,               // domain, title, forward_query, callback_data, transition_mode
    'random'                                     // domain_strategy
);

echo $response->getDomain(); // the auto-picked host
```

Contradictory domain options are rejected **client-side** before any request,
as an `InvalidRequestException`: an explicit `domain` together with a
`domainStrategy`, or a `domainGroup` without a `domainStrategy`. An empty pool
(no domains, or an empty group) is still reported by the server as a
`ValidationException` (422).

### Browsing the registry

Pick a domain or group up front with two read-only endpoints:

```php
foreach ($client->listDomainGroups() as $group) {
    echo $group->getCode().' '.$group->getName().' ('.$group->getDomainsCount().")\n";
}

// All domains, or only those in a group (by code).
$domains = $client->listDomains();
$inGroup = $client->listDomains('campaigns');

foreach ($domains as $domain) {
    echo $domain->getDomain().($domain->isDefault() ? ' (default)' : '')."\n";
}
```

Both endpoints use a **separate** rate-limit bucket from link creation, so
browsing the registry never eats into your link-creation budget.

## Error handling

Every documented HTTP outcome maps to a typed exception. Because the specific
exceptions extend `ApiException`, catch them **most-specific first**:

```php
use Vaslv\Brevity\Exceptions\AuthenticationException;
use Vaslv\Brevity\Exceptions\ValidationException;
use Vaslv\Brevity\Exceptions\RateLimitException;
use Vaslv\Brevity\Exceptions\ApiException;
use Vaslv\Brevity\Exceptions\TransportException;

try {
    $response = $client->createLink($request);
} catch (ValidationException $e) {              // 422
    foreach ($e->getErrors() as $field => $messages) {
        // $field => string[] of messages
    }
} catch (AuthenticationException $e) {           // 401 — missing / invalid token
    // re-issue or fix the token
} catch (RateLimitException $e) {                // 429
    $waitSeconds = $e->getRetryAfter();          // int|null
} catch (ApiException $e) {                       // other 4xx/5xx (incl. 403)
    // 403 = token present but lacks the links:create ability ($e->getStatusCode() === 403)
    $status = $e->getStatusCode();
    $body   = $e->getResponseBody();             // decoded JSON, array|null
} catch (TransportException $e) {                 // network / timeout
    // retries already exhausted
}
```

| Exception | HTTP | Extra accessors |
|---|---|---|
| `AuthenticationException` | 401 | `getStatusCode()`, `getResponseBody()` |
| `ValidationException` | 422 | `getErrors(): array<string, string[]>` |
| `RateLimitException` | 429 | `getRetryAfter(): ?int` |
| `ApiException` | other 4xx/5xx | `getStatusCode(): int`, `getResponseBody(): ?array` |
| `TransportException` | — (network/timeout) | standard `Throwable` |
| `InvalidRequestException` | — (client-side, pre-request) | a `\InvalidArgumentException` thrown when domain options contradict each other |

`InvalidRequestException` is raised while **building** a `CreateLinkRequest`
(or by `createSimpleLink()`), before any HTTP call — see [Domains](#domains).
It extends `\InvalidArgumentException`, not `ApiException`, so it is not caught
by the `ApiException` arm above.

## Rate limiting, retries & timeouts

- **Rate limit:** 120 requests/minute per service. On `429` the SDK throws
  `RateLimitException`; read `getRetryAfter()` to back off.
- **Retries:** network errors and `5xx` are retried up to `retries` times.
  `4xx` (including `422` and `429`) are **never** retried — they surface
  immediately.
- **Timeouts:** `timeout` (default 7 s) and `connect_timeout` (default 5 s).

## API reference

### `BrevityClient`

| Method | Signature |
|---|---|
| `__construct` | `(array $config, ?ClientInterface $httpClient = null)` |
| `createSimpleLink` | `(string $url, ?string $domain = null, ?string $title = null, ?bool $forwardQuery = null, ?array $callbackData = null, ?string $transitionMode = null, ?string $domainStrategy = null, ?string $domainGroup = null): CreateLinkResponse` |
| `createLink` | `(CreateLinkRequest $request): CreateLinkResponse` |
| `listDomains` | `(?string $group = null): Domain[]` |
| `listDomainGroups` | `(): DomainGroup[]` |

### Request DTOs

**`CreateLinkRequest`** — `__construct(?string $domain, ?string $title, ?bool $forwardQuery, ?array $callbackData, CreateLinkRule[] $rules, ?string $domainStrategy = null, ?string $domainGroup = null)`

| Getter | Returns | Maps to |
|---|---|---|
| `getDomain()` | `?string` | `domain` |
| `getTitle()` | `?string` | `title` |
| `getForwardQuery()` | `?bool` | `forward_query` |
| `getCallbackData()` | `?array` | `callback_data` |
| `getRules()` | `CreateLinkRule[]` | `rules` |
| `getDomainStrategy()` | `?string` | `domain_strategy` |
| `getDomainGroup()` | `?string` | `domain_group` |
| `toArray()` | `array` | full JSON body (omits `null` optional fields) |

**`CreateLinkRule`** — `__construct(string $url, ?CreateLinkCondition $condition = null, ?string $transitionMode = null)`

| Getter | Returns |
|---|---|
| `getUrl()` | `string` |
| `getCondition()` | `?CreateLinkCondition` |
| `getTransitionMode()` | `?string` |

**`CreateLinkCondition`** — `__construct(string $type, ?array $data = null)`

| Getter | Returns |
|---|---|
| `getType()` | `string` |
| `getData()` | `?array` |

### Response DTOs

**`CreateLinkResponse`** — returned by both client methods.

| Getter | Returns | Notes |
|---|---|---|
| `getUrl()` | `string` | Final short URL — the value you share. |
| `getDomain()` | `?string` | `null` when the link has no domain (resolves via `APP_URL`). |
| `getCode()` | `string` | Short code, e.g. `AbC12345`. |
| `getTitle()` | `?string` | |
| `getForwardQuery()` | `?bool` | |
| `getCallbackData()` | `?array` | |
| `getRules()` | `CreateLinkResponseRule[]` | Server-normalized rules. |

**`CreateLinkResponseRule`**

| Getter | Returns |
|---|---|
| `getUrl()` | `string` (server-normalized — query params may be re-sorted) |
| `getCondition()` | `?CreateLinkCondition` |
| `getTransitionMode()` | `?string` |

**`Domain`** — element of `listDomains()`.

| Getter | Returns | Maps to |
|---|---|---|
| `getDomain()` | `string` | `domain` (the host) |
| `getUrl()` | `string` | `url` (host as an `https://` address) |
| `isDefault()` | `bool` | `is_default` |

**`DomainGroup`** — element of `listDomainGroups()`.

| Getter | Returns | Maps to |
|---|---|---|
| `getCode()` | `string` | `code` (use as `group` / `domainGroup`) |
| `getName()` | `string` | `name` |
| `getDomainsCount()` | `int` | `domains_count` |

The full request/response field contract, validation rules and ready-to-use
test payloads live in [API.md](./API.md).

## Testing

With a local PHP + Composer toolchain:

```bash
composer install
vendor/bin/phpunit
```

Or, without installing PHP locally, in a reproducible PHP 8.4 container
(matches the locked dev dependencies):

```bash
docker compose run --rm tests                 # composer install + phpunit
docker compose run --rm tests vendor/bin/pint --test   # code-style check
```

## License

MIT.

---

# Brevity PHP SDK (Русский)

PHP SDK для API сервиса коротких ссылок **Brevity** — создавайте короткие
ссылки с приоритетными правилами перехода, временными условиями, режимами
перехода и исходящими колбеками на клик, из чистого PHP или Laravel.

> 📄 Полный контракт API (эндпоинты, поля, валидация) — в [API.md](./API.md).

## Оглавление

- [Возможности](#возможности)
- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт (чистый PHP)](#быстрый-старт-чистый-php)
- [Конфигурация](#конфигурация)
- [Интеграция с Laravel](#интеграция-с-laravel)
- [Условия](#условия)
- [Режимы перехода](#режимы-перехода)
- [Домены](#домены)
- [Обработка ошибок](#обработка-ошибок)
- [Лимиты, ретраи и таймауты](#лимиты-ретраи-и-таймауты)
- [Справочник API](#справочник-api)
- [Тесты](#тесты)
- [Лицензия](#лицензия)

## Возможности

- Создание короткой ссылки через `POST /api/links` за один вызов.
- Хелпер `createSimpleLink()` для частого случая «одна ссылка — один URL».
- Автоподбор домена по стратегии (`random` / `round_robin` / `coldest`),
  опционально в пределах группы доменов.
- Просмотр справочника доменов через `listDomains()` / `listDomainGroups()`.
- Проверка противоречивых опций домена на клиенте — без лишнего запроса к серверу.
- Строго типизированные DTO запроса/ответа (никаких ассоциативных массивов наугад).
- Типизированные исключения для каждого документированного HTTP-исхода:
  - `AuthenticationException` (401)
  - `ValidationException` (422, отдаёт ошибки по полям)
  - `RateLimitException` (429, отдаёт `Retry-After`)
  - `ApiException` (прочие 4xx/5xx)
  - `TransportException` (сеть/таймаут)
- Автоматические ретраи для сетевых сбоев и `5xx` (никогда для `4xx`).
- Полноценная поддержка Laravel: авто-обнаружение Service Provider + Facade `Brevity`.

## Требования

| Зависимость | Версия |
|---|---|
| PHP | `>= 7.1` |
| ext-json | `*` |
| `guzzlehttp/guzzle` | `^6.5` или `^7.0` |
| Laravel (опционально) | `5.8` – `13.x` |

## Установка

```bash
composer require vaslv/brevity-php-sdk
```

Из админки Brevity вам понадобятся:

1. **Сервис** (`Service`) — владелец ссылок, получатель колбеков.
2. **API-токен** этого сервиса со способностью `links:create`.
3. **Технический хост** — базовый URL (тот же хост, что и `APP_URL`,
   например `https://brevity.example.com`). Домены коротких ссылок
   обслуживают только редиректы и отвечают `404` на `/api/...`.

Как их получить — см. [API.md §1–§3](./API.md).

## Быстрый старт (чистый PHP)

Простейший случай — один целевой URL, без условий:

```php
<?php

use Vaslv\Brevity\BrevityClient;

$client = new BrevityClient([
    'base_uri' => 'https://brevity.example.com',
    'token'    => 'your-sanctum-token',
]);

$response = $client->createSimpleLink('https://example.com/landing');

echo $response->getUrl();  // https://short.example.com/AbC12345
```

`createSimpleLink()` принимает опциональные `domain`, `title`,
`forwardQuery`, `callbackData` и `transitionMode`:

```php
$response = $client->createSimpleLink(
    'https://example.com/landing',
    'short.example.com',           // домен (должен быть в справочнике)
    'Кампания весна-2026',         // заголовок
    true,                          // forward_query
    ['campaign_id' => 'cmp-42'],   // callback_data
    'delayed'                      // transition_mode
);
```

Для полного контроля — несколько правил в порядке приоритета, условия и
режимы перехода — соберите `CreateLinkRequest`:

```php
<?php

use Vaslv\Brevity\BrevityClient;
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;

$client = new BrevityClient([
    'base_uri'        => 'https://brevity.example.com',
    'token'           => 'your-sanctum-token',
    'timeout'         => 7,
    'connect_timeout' => 5,
    'retries'         => 1,
]);

$request = new CreateLinkRequest(
    'short.example.com',             // домен
    'Кампания весна-2026',           // заголовок
    true,                            // forward_query
    ['campaign_id' => 'cmp-42'],     // callback_data
    [
        // Сначала — высший приоритет. Сервер применяет первое правило, чьё
        // условие истинно; правило без условия истинно всегда.
        new CreateLinkRule(
            'https://example.com/sale',
            new CreateLinkCondition('time_before', [
                'before' => '2026-03-05T10:00:00+00:00',
            ]),
            'delayed'
        ),
        // Fallback — без условия, срабатывает после окончания акции.
        new CreateLinkRule('https://example.com/home'),
    ]
);

$response = $client->createLink($request);

echo $response->getUrl();                           // короткая ссылка
echo $response->getCode();                          // AbC12345
echo $response->getRules()[0]->getTransitionMode(); // delayed
```

## Конфигурация

`BrevityClient` создаётся из обычного массива конфигурации:

| Ключ | Тип | По умолчанию | Описание |
|---|---|---|---|
| `base_uri` | `string` | `''` | Базовый URL **технического хоста** (не домен короткой ссылки). |
| `token` | `string` | `''` | Sanctum-токен со способностью `links:create`. |
| `timeout` | `float` | `7.0` | Общий таймаут запроса, секунды. |
| `connect_timeout` | `float` | `5.0` | Таймаут соединения, секунды. |
| `retries` | `int` | `1` | Ретраи для сетевых/`5xx` сбоев. Всего попыток = `retries + 1`. |

Вторым аргументом конструктора можно передать свой Guzzle-клиент (удобно
для тестов/middleware):

```php
$client = new BrevityClient($config, $myGuzzleClient);
```

## Интеграция с Laravel

Service Provider и Facade обнаруживаются автоматически (Laravel 5.8+). Ручная
регистрация, если auto-discovery отключён:

- Provider: `Vaslv\Brevity\Laravel\BrevityServiceProvider::class`
- Alias: `Brevity` → `Vaslv\Brevity\Laravel\BrevityFacade::class`

Публикация конфига:

```bash
php artisan vendor:publish --tag=brevity-config
```

Настройка через `.env`:

```dotenv
BREVITY_BASE_URI=https://brevity.example.com
BREVITY_TOKEN=your-sanctum-token
BREVITY_TIMEOUT=7
BREVITY_CONNECT_TIMEOUT=5
BREVITY_RETRIES=1
```

Использование через Facade (или резолв `BrevityClient` из контейнера):

```php
use Brevity;

$response = Brevity::createSimpleLink('https://example.com', null, 'Моя ссылка');
```

```php
use Vaslv\Brevity\BrevityClient;

public function __construct(private BrevityClient $brevity) {}
```

## Условия

Условие делает правило выборочным: оно применяется, только пока условие
истинно; иначе сервер пробует следующее правило по приоритету. Правило
**без** условия истинно всегда — ставьте его последним как fallback.

| `type` | Назначение | `data` |
|---|---|---|
| `time_before` | Активно, пока текущее время **раньше** указанного момента | `{ "before": "<ISO 8601>" }` |

`before` — в формате `Y-m-d\TH:i:sP`, например `2026-03-05T10:00:00+00:00`,
и обязателен при `time_before`.

```php
new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']);
```

Набор типов условий расширяется на стороне сервера; передавайте
`condition.data` без изменений — SDK его не трансформирует. См. [API.md §6](./API.md).

## Режимы перехода

Как сервер отвечает посетителю при срабатывании правила:

| Значение | Поведение |
|---|---|
| `direct` (или `null`) | HTTP-редирект 302. По умолчанию. |
| `delayed` | HTML-страница с авто-редиректом после отсчёта (по умолчанию 5 с). |
| `manual` | HTML-страница с кнопкой «продолжить». |

Если не передан, в ответе поле `null`, что эквивалентно `direct`.

## Домены

Сервер определяет домен ссылки в таком порядке: явный `domain` →
`domain_strategy` → домен «по умолчанию» из справочника → без домена (резолв
через `APP_URL`). Явный `domain` и `domain_strategy` — **взаимоисключимы**.

### Автоподбор по стратегии

Чтобы получить ссылку на домене не по умолчанию, не называя конкретный,
передайте стратегию. Пул — весь справочник либо одна группа, если задан
`domainGroup` (код группы).

| Стратегия | Как выбирает |
|---|---|
| `random` | Случайный домен из пула. |
| `round_robin` | Наименее недавно использованный — каждой новой ссылке следующий домен. |
| `coldest` | Самый «холодный» — с наименьшим числом ссылок за последние 30 дней. |

```php
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;

// Полный запрос: 6-й/7-й аргументы конструктора — $domainStrategy и $domainGroup.
$request = new CreateLinkRequest(
    null,                                       // domain — со стратегией должен быть null
    null, null, null,                           // title, forward_query, callback_data
    [new CreateLinkRule('https://example.com/landing')],
    'round_robin',                              // domain_strategy
    'campaigns'                                 // domain_group — код группы (опционально)
);
$response = $client->createLink($request);

// Однострочник: domainStrategy / domainGroup — 7-й/8-й аргументы.
$response = $client->createSimpleLink(
    'https://example.com/landing',
    null, null, null, null, null,               // domain, title, forward_query, callback_data, transition_mode
    'random'                                     // domain_strategy
);

echo $response->getDomain(); // подобранный хост
```

Противоречивые опции домена отклоняются **на клиенте**, ещё до запроса, как
`InvalidRequestException`: явный `domain` вместе с `domainStrategy` либо
`domainGroup` без `domainStrategy`. Пустой пул (нет доменов или группа пуста)
по-прежнему приходит с сервера как `ValidationException` (422).

### Просмотр справочника

Выбрать домен или группу заранее можно двумя read-only эндпоинтами:

```php
foreach ($client->listDomainGroups() as $group) {
    echo $group->getCode().' '.$group->getName().' ('.$group->getDomainsCount().")\n";
}

// Все домены или только из группы (по коду).
$domains = $client->listDomains();
$inGroup = $client->listDomains('campaigns');

foreach ($domains as $domain) {
    echo $domain->getDomain().($domain->isDefault() ? ' (по умолчанию)' : '')."\n";
}
```

У обоих эндпоинтов **отдельный** счётчик лимита от создания ссылок, поэтому
чтение справочника не расходует бюджет создания ссылок.

## Обработка ошибок

Каждый документированный HTTP-исход — это типизированное исключение.
Поскольку частные исключения наследуют `ApiException`, ловите их
**от частного к общему**:

```php
use Vaslv\Brevity\Exceptions\AuthenticationException;
use Vaslv\Brevity\Exceptions\ValidationException;
use Vaslv\Brevity\Exceptions\RateLimitException;
use Vaslv\Brevity\Exceptions\ApiException;
use Vaslv\Brevity\Exceptions\TransportException;

try {
    $response = $client->createLink($request);
} catch (ValidationException $e) {              // 422
    foreach ($e->getErrors() as $field => $messages) {
        // $field => массив строк-сообщений
    }
} catch (AuthenticationException $e) {           // 401 — нет/невалидный токен
    // перевыпустите или исправьте токен
} catch (RateLimitException $e) {                // 429
    $waitSeconds = $e->getRetryAfter();          // int|null
} catch (ApiException $e) {                       // прочие 4xx/5xx (вкл. 403)
    // 403 = токен есть, но без способности links:create ($e->getStatusCode() === 403)
    $status = $e->getStatusCode();
    $body   = $e->getResponseBody();             // декодированный JSON, array|null
} catch (TransportException $e) {                 // сеть / таймаут
    // ретраи уже исчерпаны
}
```

| Исключение | HTTP | Доп. методы |
|---|---|---|
| `AuthenticationException` | 401 | `getStatusCode()`, `getResponseBody()` |
| `ValidationException` | 422 | `getErrors(): array<string, string[]>` |
| `RateLimitException` | 429 | `getRetryAfter(): ?int` |
| `ApiException` | прочие 4xx/5xx | `getStatusCode(): int`, `getResponseBody(): ?array` |
| `TransportException` | — (сеть/таймаут) | стандартный `Throwable` |
| `InvalidRequestException` | — (на клиенте, до запроса) | `\InvalidArgumentException`, бросается при противоречивых опциях домена |

`InvalidRequestException` бросается при **сборке** `CreateLinkRequest` (или из
`createSimpleLink()`), ещё до HTTP-запроса — см. [Домены](#домены). Он
наследует `\InvalidArgumentException`, а не `ApiException`, поэтому не
перехватывается веткой `ApiException` выше.

## Лимиты, ретраи и таймауты

- **Лимит:** 120 запросов/мин на сервис. На `429` SDK бросает
  `RateLimitException`; читайте `getRetryAfter()` для бэкоффа.
- **Ретраи:** сетевые ошибки и `5xx` повторяются до `retries` раз. `4xx`
  (включая `422` и `429`) **не** повторяются — они отдаются сразу.
- **Таймауты:** `timeout` (по умолчанию 7 с) и `connect_timeout` (5 с).

## Справочник API

### `BrevityClient`

| Метод | Сигнатура |
|---|---|
| `__construct` | `(array $config, ?ClientInterface $httpClient = null)` |
| `createSimpleLink` | `(string $url, ?string $domain = null, ?string $title = null, ?bool $forwardQuery = null, ?array $callbackData = null, ?string $transitionMode = null, ?string $domainStrategy = null, ?string $domainGroup = null): CreateLinkResponse` |
| `createLink` | `(CreateLinkRequest $request): CreateLinkResponse` |
| `listDomains` | `(?string $group = null): Domain[]` |
| `listDomainGroups` | `(): DomainGroup[]` |

### DTO запроса

**`CreateLinkRequest`** — `__construct(?string $domain, ?string $title, ?bool $forwardQuery, ?array $callbackData, CreateLinkRule[] $rules, ?string $domainStrategy = null, ?string $domainGroup = null)`

| Метод | Возвращает | Поле |
|---|---|---|
| `getDomain()` | `?string` | `domain` |
| `getTitle()` | `?string` | `title` |
| `getForwardQuery()` | `?bool` | `forward_query` |
| `getCallbackData()` | `?array` | `callback_data` |
| `getRules()` | `CreateLinkRule[]` | `rules` |
| `getDomainStrategy()` | `?string` | `domain_strategy` |
| `getDomainGroup()` | `?string` | `domain_group` |
| `toArray()` | `array` | полное тело JSON (опускает `null`-поля) |

**`CreateLinkRule`** — `__construct(string $url, ?CreateLinkCondition $condition = null, ?string $transitionMode = null)`

| Метод | Возвращает |
|---|---|
| `getUrl()` | `string` |
| `getCondition()` | `?CreateLinkCondition` |
| `getTransitionMode()` | `?string` |

**`CreateLinkCondition`** — `__construct(string $type, ?array $data = null)`

| Метод | Возвращает |
|---|---|
| `getType()` | `string` |
| `getData()` | `?array` |

### DTO ответа

**`CreateLinkResponse`** — возвращается обоими методами клиента.

| Метод | Возвращает | Примечание |
|---|---|---|
| `getUrl()` | `string` | Итоговая короткая ссылка — её и делитесь. |
| `getDomain()` | `?string` | `null`, если у ссылки нет домена (резолв через `APP_URL`). |
| `getCode()` | `string` | Короткий код, напр. `AbC12345`. |
| `getTitle()` | `?string` | |
| `getForwardQuery()` | `?bool` | |
| `getCallbackData()` | `?array` | |
| `getRules()` | `CreateLinkResponseRule[]` | Нормализованные сервером правила. |

**`CreateLinkResponseRule`**

| Метод | Возвращает |
|---|---|
| `getUrl()` | `string` (нормализован сервером — query-параметры могут быть пересортированы) |
| `getCondition()` | `?CreateLinkCondition` |
| `getTransitionMode()` | `?string` |

**`Domain`** — элемент `listDomains()`.

| Метод | Возвращает | Поле |
|---|---|---|
| `getDomain()` | `string` | `domain` (хост) |
| `getUrl()` | `string` | `url` (хост как `https://`-адрес) |
| `isDefault()` | `bool` | `is_default` |

**`DomainGroup`** — элемент `listDomainGroups()`.

| Метод | Возвращает | Поле |
|---|---|---|
| `getCode()` | `string` | `code` (используйте как `group` / `domainGroup`) |
| `getName()` | `string` | `name` |
| `getDomainsCount()` | `int` | `domains_count` |

Полный контракт полей запроса/ответа, правила валидации и готовые тестовые
payload'ы — в [API.md](./API.md).

## Тесты

С локальным PHP и Composer:

```bash
composer install
vendor/bin/phpunit
```

Либо без установки PHP — в воспроизводимом контейнере на PHP 8.4
(совпадает с зафиксированными dev-зависимостями):

```bash
docker compose run --rm tests                 # composer install + phpunit
docker compose run --rm tests vendor/bin/pint --test   # проверка стиля
```

## Лицензия

MIT.
