# Brevity PHP SDK

PHP-клиент для API движка коротких ссылок [Brevity](https://github.com/vaslv/brevity).

**English version: [README.md](./README.md)**

[![Packagist Version](https://img.shields.io/packagist/v/vaslv/brevity-php-sdk)](https://packagist.org/packages/vaslv/brevity-php-sdk)
[![CI](https://github.com/vaslv/brevity-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/vaslv/brevity-php-sdk/actions/workflows/ci.yml)

## Возможности

- Полная поверхность `/api/v1`: создание ссылок (`POST /links`), чтение
  состояния со сводкой кликов (`GET /links/{code}`), частичное обновление
  (`PATCH /links/{code}`), справочники доменов и групп.
- Типизированные DTO запросов и ответов: правила с условиями (до 10,
  И-семантика), A/B-сплит со взвешенными вариантами, окно активности
  (`valid_since` / `valid_until`) и лимит переходов (`max_clicks`).
- Ошибки RFC 7807: исключения выбираются по стабильному машинному коду `type`,
  а не по текстам или голым HTTP-статусам.
- Транспорт по рекомендациям контракта: ретраи только на сетевых сбоях и 5xx,
  везде `Accept: application/json`, очевидные ошибки отклоняются на стороне
  клиента ещё до HTTP-запроса.
- Мост для Laravel (5.8+): auto-discovery сервис-провайдера, фасад и
  публикуемый конфиг.
- Работает на PHP 7.1+ с Guzzle 6.5 или 7.

## Требования

- PHP >= 7.1 с `ext-json`
- `guzzlehttp/guzzle` ^6.5 || ^7.0
- опционально: Laravel 5.8+ для моста

## Установка

```bash
composer require vaslv/brevity-php-sdk
```

## Быстрый старт

```php
use Vaslv\Brevity\BrevityClient;

$client = new BrevityClient([
    'base_uri' => 'https://brevity.example.com', // технический хост, не домен коротких ссылок
    'token' => getenv('BREVITY_TOKEN'),
]);

$link = $client->createSimpleLink('https://example.com/landing');
echo $link->getUrl(); // https://short.example.com/AbC12345
```

API доступен **только на техническом хосте** (том, что за `APP_URL`); домены
коротких ссылок отвечают 404 на любой запрос `/api/...`. Токен выпускается из
админки и должен нести способность `links:create` — новые токены получают
также `links:read` и `links:update`.

## Использование

### Условия и A/B-варианты

```php
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;
use Vaslv\Brevity\DTO\CreateLinkVariant;

$request = new CreateLinkRequest(
    null,                            // domain (null → домен по умолчанию)
    'Весенняя кампания',             // title
    true,                            // forward_query
    ['click_id' => '{{click.id}}'],  // шаблон callback_data
    [
        // Правила пробуются по порядку; выигрывает первое, у которого совпали все условия.
        new CreateLinkRule('https://example.com/sale', [
            new CreateLinkCondition('time_before', ['before' => '2026-03-05T10:00:00+00:00']),
            new CreateLinkCondition('device', ['device' => 'mobile']),
        ]),
        // A/B-сплит: веса 1:3, sticky по посетителю.
        new CreateLinkRule('https://example.com/control', [], null, [
            new CreateLinkVariant('https://example.com/a', 1, 'A'),
            new CreateLinkVariant('https://example.com/b', 3, 'B'),
        ]),
        // Безусловный fallback.
        new CreateLinkRule('https://example.com/home'),
    ]
);

$link = $client->createLink($request);
```

Типы условий: `time_before`, `after_date`, `query_param`, `ip_address`,
`device`, `language` — форма `data` каждого описана в [API.md](./API.md) (§6).
`data` условий проходит через SDK без преобразований.

### Окно активности и лимит переходов

```php
$request = new CreateLinkRequest(
    null, null, null, null,
    [new CreateLinkRule('https://example.com/landing')],
    null, null,
    new DateTimeImmutable('2026-08-01T00:00:00+00:00'), // valid_since
    new DateTimeImmutable('2026-09-01T00:00:00+00:00'), // valid_until (включительно)
    100                                                 // max_clicks (боты тоже считаются)
);
```

Вне окна и после исчерпания лимита ссылка отвечает 404.

### Выбор домена

```php
// Явный домен (должен существовать в справочнике):
$client->createSimpleLink('https://example.com/x', 'go.example.com');

// Автоподбор: 'random' / 'round_robin' / 'coldest', опционально в пределах группы:
$request = new CreateLinkRequest(
    null, null, null, null,
    [new CreateLinkRule('https://example.com/x')],
    'round_robin',
    'campaigns'
);

// Справочники:
$domains = $client->listDomains();     // Domain[]; или listDomains('campaigns')
$groups = $client->listDomainGroups(); // DomainGroup[]
```

### Чтение ссылки

```php
$link = $client->getLink('AbC12345');  // нужна способность links:read

$link->getClicks()->getTotal();        // все клики, включая ботов
$link->getClicks()->getNonBots();      // может отставать на секунды
```

### Изменение ссылки

```php
use Vaslv\Brevity\DTO\UpdateLinkRequest;

$patch = (new UpdateLinkRequest)
    ->setValidUntil(new DateTimeImmutable('2026-12-31T23:59:59+00:00'))
    ->setMaxClicks(null);              // явный null снимает лимит

$client->updateLink('AbC12345', $patch); // нужна способность links:update
```

Нетронутые поля сохраняют серверные значения; `setRules()` заменяет весь
набор правил. `code` и `domain` изменить нельзя.

## Обработка ошибок

Каждая ошибка `/api/v1` — RFC 7807 problem; SDK выбирает исключение по
стабильному коду `type`:

| `type` | HTTP | Исключение |
|---|---|---|
| `unauthenticated` | 401 | `AuthenticationException` |
| `missing-ability` | 403 | `MissingAbilityException` |
| `forbidden` | 403 | `ForbiddenException` |
| `not-found` | 404 | `NotFoundException` |
| `validation-error` | 422 | `ValidationException` (`getErrors()`) |
| `too-many-requests` | 429 | `RateLimitException` (`getRetryAfter()`) |
| `http-error`, `server-error`, незнакомый | прочие | `ApiException` |

Все перечисленные наследуют `ApiException` (`getStatusCode()`,
`getResponseBody()`, `getProblemType()`); `MissingAbilityException` наследует
`ForbiddenException`. Сеть/таймауты — `TransportException`; клиентские ошибки
использования (противоречивые опции, пустой патч) — `InvalidRequestException`
ещё до HTTP-запроса.

```php
use Vaslv\Brevity\Exceptions\ApiException;
use Vaslv\Brevity\Exceptions\RateLimitException;
use Vaslv\Brevity\Exceptions\TransportException;
use Vaslv\Brevity\Exceptions\ValidationException;

try {
    $link = $client->createLink($request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();    // поле => сообщения[]
} catch (RateLimitException $e) {
    $wait = $e->getRetryAfter();  // секунды или null
} catch (ApiException $e) {
    $type = $e->getProblemType(); // стабильный машинный код или null
} catch (TransportException $e) {
    // сетевой сбой после настроенных ретраев
}
```

Лимиты: два независимых бюджета — чтение и запись — по 120 запросов в минуту
на сервис.

## Laravel

Сервис-провайдер и фасад `Brevity` подхватываются auto-discovery на
Laravel 5.8+. Настройка через `.env`:

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

Публикация конфига при необходимости:
`php artisan vendor:publish --tag=brevity-config`.

## Тестирование

Локальный PHP не нужен — тесты запускаются в Docker:

```bash
docker compose run --rm tests
```

## Документация

Полный контракт API — в [API.md](./API.md), это копия канонического
английского `docs/03-api.md` [основного репозитория](https://github.com/vaslv/brevity);
русское зеркало там же, в `docs/ru/`.

## Лицензия

[MIT](./LICENSE)
