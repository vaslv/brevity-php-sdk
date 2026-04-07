# Brevity PHP SDK

PHP SDK for the Brevity short links API.

## Features

- Create short links via `POST /api/links`
- Create a simple short link with a single target URL via `createSimpleLink()`
- Request/response DTOs
- Typed exceptions:
- `AuthenticationException` (401)
- `ValidationException` (422)
- `ApiException` (other 4xx/5xx)
- `TransportException` (network/transport errors)
- Laravel Service Provider + Facade (`Laravel 5.8+`)

## Installation

```bash
composer require vaslv/brevity-php-sdk
```

## Quick Start (Plain PHP)

Simple case without conditions:

```php
<?php

use Vaslv\Brevity\BrevityClient;

$client = new BrevityClient([
    'base_uri' => 'https://your-host',
    'token' => 'your-sanctum-token',
]);

$response = $client->createSimpleLink(
    'https://example.com/landing',
    'short.example.com',
    'Campaign link',
    true,
    ['campaign_id' => 'cmp-42']
);
```

Full request with explicit rules:

```php
<?php

use Vaslv\Brevity\BrevityClient;
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;

$client = new BrevityClient([
    'base_uri' => 'https://your-host',
    'token' => 'your-sanctum-token',
    'timeout' => 7,
    'connect_timeout' => 5,
    'retries' => 1,
]);

$request = new CreateLinkRequest(
    'short.example.com',
    'Campaign link',
    true,
    ['campaign_id' => 'cmp-42'],
    [
        new CreateLinkRule(
            'https://example.com/landing?b=2&a=1',
            new CreateLinkCondition('time_before', [
                'before' => '2026-03-05T10:00:00+00:00',
            ]),
            'delayed'
        ),
    ]
);

$response = $client->createLink($request);
echo $response->getUrl();
```

## Laravel 5.8+

The provider and facade support auto-discovery. For manual registration:

- Provider: `Vaslv\Brevity\Laravel\BrevityServiceProvider::class`
- Alias: `Brevity => Vaslv\Brevity\Laravel\BrevityFacade::class`

Publish config:

```bash
php artisan vendor:publish --tag=brevity-config
```

`.env`:

```dotenv
BREVITY_BASE_URI=https://your-host
BREVITY_TOKEN=your-sanctum-token
BREVITY_TIMEOUT=7
BREVITY_CONNECT_TIMEOUT=5
BREVITY_RETRIES=1
```

Usage:

```php
<?php

$response = \Brevity::createSimpleLink('https://example.com', null, 'My Link');
```

---

# Brevity PHP SDK (Русский)

PHP SDK для API сервиса коротких ссылок Brevity.

## Возможности

- Создание короткой ссылки через `POST /api/links`
- Создание простой короткой ссылки с одним URL через `createSimpleLink()`
- DTO для request/response
- Типизированные исключения:
- `AuthenticationException` (401)
- `ValidationException` (422)
- `ApiException` (остальные 4xx/5xx)
- `TransportException` (сетевые/транспортные проблемы)
- Laravel Service Provider + Facade (`Laravel 5.8+`)

## Установка

```bash
composer require vaslv/brevity-php-sdk
```

## Быстрый старт (чистый PHP)

Простой случай без условий:

```php
<?php

use Vaslv\Brevity\BrevityClient;

$client = new BrevityClient([
    'base_uri' => 'https://your-host',
    'token' => 'your-sanctum-token',
]);

$response = $client->createSimpleLink(
    'https://example.com/landing',
    'short.example.com',
    'Campaign link',
    true,
    ['campaign_id' => 'cmp-42']
);
```

Полный запрос с явными правилами:

```php
<?php

use Vaslv\Brevity\BrevityClient;
use Vaslv\Brevity\DTO\CreateLinkCondition;
use Vaslv\Brevity\DTO\CreateLinkRequest;
use Vaslv\Brevity\DTO\CreateLinkRule;

$client = new BrevityClient([
    'base_uri' => 'https://your-host',
    'token' => 'your-sanctum-token',
    'timeout' => 7,
    'connect_timeout' => 5,
    'retries' => 1,
]);

$request = new CreateLinkRequest(
    'short.example.com',
    'Campaign link',
    true,
    ['campaign_id' => 'cmp-42'],
    [
        new CreateLinkRule(
            'https://example.com/landing?b=2&a=1',
            new CreateLinkCondition('time_before', [
                'before' => '2026-03-05T10:00:00+00:00',
            ]),
            'delayed'
        ),
    ]
);

$response = $client->createLink($request);
echo $response->getUrl();
```

## Laravel 5.8+

Провайдер и facade поддерживают auto-discovery. Для ручной регистрации:

- Provider: `Vaslv\Brevity\Laravel\BrevityServiceProvider::class`
- Alias: `Brevity => Vaslv\Brevity\Laravel\BrevityFacade::class`

Публикация конфига:

```bash
php artisan vendor:publish --tag=brevity-config
```

`.env`:

```dotenv
BREVITY_BASE_URI=https://your-host
BREVITY_TOKEN=your-sanctum-token
BREVITY_TIMEOUT=7
BREVITY_CONNECT_TIMEOUT=5
BREVITY_RETRIES=1
```

Использование:

```php
<?php

$response = \Brevity::createSimpleLink('https://example.com', null, 'My Link');
```
