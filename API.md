# Работа с API

Практическое руководство для тех, кто интегрирует свою систему с Brevity:
как получить API-токен, аутентифицироваться и создавать короткие ссылки.

> Единый источник по HTTP API: как начать работать, полный список полей,
> правила валидации, колбеки и готовые тестовые payload'ы для авторов SDK.
> Терминология — в [GLOSSARY.md](./GLOSSARY.md).

---

## 1. Что вам понадобится

Чтобы вызывать API, нужны две вещи:

1. **Сервис** (`Service`) — запись в системе, которая владеет ссылками и
   получает колбеки. Создаётся в админке (раздел **Основное → Сервисы**).
2. **API-токен** этого сервиса со способностью (ability) `links:create`.

Токен «представляет» сервис: все ссылки, созданные с этим токеном,
привязываются к сервису-владельцу, а лимиты считаются на сервис.

### Как получить токен

Токен выпускается из админки, программного эндпоинта для этого нет:

1. Откройте сервис: **Основное → Сервисы → \<нужный сервис\>**.
2. Нажмите **«Создать токен»** (кнопка с иконкой ключа в шапке).
3. Опционально выберите срок действия (30 / 90 / 365 дней). Без выбора
   токен бессрочный.
4. Скопируйте показанный токен **сразу** — он отображается один раз и в
   открытом виде больше не доступен.

Токен автоматически получает ровно одну способность — `links:create`
(принцип наименьших привилегий). Просроченные токены периодически
вычищаются командой `sanctum:prune-expired` по расписанию.

Формат токена: `<id>|<префикс><случайная-часть>`. Префикс нужен для
сканеров утечек секретов; в заголовок `Authorization` передаётся строка
целиком, как её показала админка.

---

## 2. Базовый URL и хост

> ⚠️ **Важно.** API доступен **только на техническом хосте** — на том же
> хосте, что и `APP_URL` (например, `https://brevity.example.com`).
> Домены коротких ссылок (`short.example.com` и т.п.) обслуживают только
> редиректы; запрос к `/api/...` на них вернёт **404**, ещё до проверки
> токена и лимитов.

```
Базовый URL:   https://<технический-хост>
Пути:          /api/links, /api/domains, /api/domain-groups
Версионирование: нет
Формат данных: application/json
```

Технический хост настраивается через `APP_TECHNICAL_HOST` (по умолчанию
берётся из хоста `APP_URL`). Узнать актуальное значение для окружения
можно у администратора.

---

## 3. Аутентификация

Передавайте токен в заголовке `Authorization` по схеме Bearer.
Рекомендуемый набор заголовков для любого запроса:

```http
Authorization: Bearer <ваш-токен>
Accept: application/json
Content-Type: application/json
```

- `Accept: application/json` обязателен — иначе ошибки валидации могут
  прийти как HTML-редирект, а не JSON.
- Токен должен нести способность `links:create`. Токены с wildcard `*`
  тоже принимаются (обратная совместимость).
- Нет токена / токен невалиден → `401`. Токен есть, но без нужной
  способности → `403`.

---

## 4. Быстрый старт

Минимальный запрос — одно правило с одним целевым URL:

```bash
curl -sS -X POST https://brevity.example.com/api/links \
  -H "Authorization: Bearer <ваш-токен>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "rules": [
      { "url": "https://example.com/landing" }
    ]
  }'
```

Ответ `201 Created`:

```json
{
  "data": {
    "url": "https://short.example.com/AbC12345",
    "domain": "short.example.com",
    "code": "AbC12345",
    "title": null,
    "forward_query": false,
    "callback_data": null,
    "rules": [
      {
        "url": "https://example.com/landing",
        "condition": null,
        "transition_mode": null
      }
    ]
  }
}
```

Готовая короткая ссылка — в поле `data.url`.

---

## 5. Создание ссылки: `POST /api/links`

Создаёт короткую ссылку и её правила перехода за один запрос.

### Тело запроса (обзор)

| Поле | Тип | Обяз. | Примечание |
|---|---|:---:|---|
| `domain` | string\|null | нет | Явный хост короткой ссылки. Должен существовать в справочнике. Взаимоисключим с `domain_strategy`/`domain_group`. Пусто и без стратегии → домен по умолчанию. |
| `domain_strategy` | string\|null | нет | Автоподбор домена: `random` / `round_robin` / `coldest`. Обязателен при `domain_group`. См. §8. |
| `domain_group` | string\|null | нет | Код группы — ограничить подбор группой доменов. Без него — по всем доменам. |
| `title` | string\|null | нет | Заголовок ссылки, до 64 символов. |
| `forward_query` | bool\|null | нет | Пробрасывать ли query-параметры при прямом редиректе. |
| `callback_data` | object\|null | нет | Шаблон полезной нагрузки колбека (до 50 ключей). См. §10. |
| `rules` | array | **да** | Правила перехода, от 1 до 50, в порядке приоритета. |
| `rules[].url` | string | **да** | Целевой URL (`http`/`https`), до 2000 байт. |
| `rules[].condition` | object\|null | нет | Условие срабатывания правила. |
| `rules[].condition.type` | string | при наличии `condition` | Тип условия (см. §6). |
| `rules[].condition.data` | object\|null | нет | Данные условия; валидируются по типу. |
| `rules[].transition_mode` | string\|null | нет | Режим перехода: `direct` / `delayed` / `manual`. |

### Как сервер обрабатывает запрос

- **Приоритет правил** определяется порядком в массиве `rules`: первый
  элемент — высший приоритет. При резолве ссылки сервер берёт первое
  правило, чьё условие истинно (правило без условия истинно всегда — его
  обычно ставят последним как fallback).
- **Целевой URL нормализуется** на сервере (нормализация + сортировка
  query-параметров), поэтому в ответе `rules[].url` может отличаться от
  отправленного порядком параметров.
- **Условия дедуплицируются**: одинаковые `(type, data)` переиспользуют
  одну запись `Condition`.
- При успехе — `201 Created` с телом ссылки.

---

## 6. Условия (`condition`)

Условие делает правило выборочным. Если условие истинно — применяется это
правило, иначе сервер пробует следующее по приоритету.

Текущий набор типов:

| `type` | Назначение | `data` |
|---|---|---|
| `time_before` | Срабатывает, пока текущее время **раньше** указанного | `{ "before": "<ISO 8601>" }` |

Формат `before` — `Y-m-d\TH:i:sP`, например `2026-03-05T10:00:00+00:00`.
Поле обязательно при `type: time_before`.

Пример: до 5 марта 2026 — на акционный лендинг, после — на обычный:

```json
{
  "rules": [
    {
      "url": "https://example.com/sale",
      "condition": {
        "type": "time_before",
        "data": { "before": "2026-03-05T10:00:00+00:00" }
      }
    },
    { "url": "https://example.com/home" }
  ]
}
```

Список типов расширяемый (через `ConditionHandler` в `ConditionRegistry`).
Актуальный перечень и форму `data` для каждого типа см. в
[GLOSSARY.md](./GLOSSARY.md).

---

## 7. Режимы перехода (`transition_mode`)

Как сервер отвечает посетителю при срабатывании правила:

| Значение | Поведение |
|---|---|
| `direct` (или `null`) | HTTP-редирект (302). Значение по умолчанию. |
| `delayed` | HTML-страница с автоматическим редиректом после обратного отсчёта. |
| `manual` | HTML-страница с кнопкой «продолжить». |

Если поле не передано, в ответе оно `null`, что эквивалентно `direct`.

---

## 8. Домены

Сервер определяет домен ссылки в таком порядке:

1. **Явный `domain`** — короткая ссылка строится на нём. Домен должен
   существовать в справочнике (иначе `422`).
2. **`domain_strategy`** (если задан) — домен подбирается автоматически по
   стратегии, см. ниже.
3. **Ни домена, ни стратегии** — берётся домен, помеченный «по умолчанию».
4. **И домена по умолчанию нет** — ссылка остаётся без домена и резолвится
   через `APP_URL` (поле `domain` в ответе будет `null`).

Поле `data.url` в ответе всегда содержит итоговую короткую ссылку с уже
подставленным доменом.

### Автоподбор домена по стратегии

Чтобы получить ссылку на домене **не по умолчанию**, не указывая конкретный
домен, передайте `domain_strategy`. Подбор идёт по пулу: домены группы
(если задан `domain_group` — код группы) либо все домены.

| Стратегия | Как выбирает |
|---|---|
| `random` | Случайный домен из пула. |
| `round_robin` | Наименее недавно использованный — домены идут по кругу, каждой следующей ссылке следующий домен. |
| `coldest` | Самый «холодный» — с наименьшим числом ссылок за период (по умолчанию 30 дней). |

- `domain` и `domain_strategy` нельзя передавать вместе (`422`).
- `domain_group` без `domain_strategy` — ошибка (`422`).
- `domain_group` — это `code` группы (всегда в нижнем регистре, сравнение
  точное); берите значение из `GET /api/domain-groups`.
- Статистика для `round_robin`/`coldest` — общая по всем сервисам.
- Если в пуле нет доменов (нет вообще или группа пуста) — `422`.

Домен по кругу из группы `campaigns`:

```bash
curl -sS -X POST https://brevity.example.com/api/links \
  -H "Authorization: Bearer <ваш-токен>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "domain_strategy": "round_robin",
    "domain_group": "campaigns",
    "rules": [ { "url": "https://example.com/landing" } ]
  }'
```

Выбранный домен вернётся в `data.domain` и `data.url`.

---

## 9. Справочник: домены и группы

Чтобы выбрать домен для ссылки, список доменов и их групп можно получить
через API. Оба эндпоинта — только чтение, требуют тот же токен со
способностью `links:create` и отдают данные в обёртке `data`.

### `GET /api/domains` — список доменов

Без параметров возвращает **все** домены. С параметром `group` (код группы)
— только домены, входящие в указанную группу.

| Параметр | Тип | Обяз. | Примечание |
|---|---|:---:|---|
| `group` | string\|null | нет | Код группы. Должен существовать (иначе `422`). Без него — все домены. |

```bash
curl -sS https://brevity.example.com/api/domains \
  -H "Authorization: Bearer <ваш-токен>" \
  -H "Accept: application/json"
```

Ответ `200 OK`:

```json
{
  "data": [
    { "domain": "short.example.com", "url": "https://short.example.com", "is_default": true },
    { "domain": "go.example.com", "url": "https://go.example.com", "is_default": false }
  ]
}
```

Только домены из группы `campaigns`:

```bash
curl -sS "https://brevity.example.com/api/domains?group=campaigns" \
  -H "Authorization: Bearer <ваш-токен>" \
  -H "Accept: application/json"
```

Поля: `domain` — хост, `url` — он же в виде `https://`-адреса, `is_default`
— используется ли домен по умолчанию. Сортировка — по `domain`.

### `GET /api/domain-groups` — список групп

Возвращает все группы доменов с числом доменов в каждой. Значение `code`
используйте как `group` в запросе доменов.

```bash
curl -sS https://brevity.example.com/api/domain-groups \
  -H "Authorization: Bearer <ваш-токен>" \
  -H "Accept: application/json"
```

Ответ `200 OK`:

```json
{
  "data": [
    { "code": "primary", "name": "Primary", "domains_count": 3 },
    { "code": "campaigns", "name": "Campaigns", "domains_count": 5 }
  ]
}
```

Поля: `code` — код группы, `name` — название, `domains_count` — число
доменов в группе. Сортировка — по `name`. Код всегда в нижнем регистре;
в фильтре `?group=` и в поле `domain_group` указывайте его точно как в
ответе (сравнение точное).

---

## 10. Колбеки (исходящие уведомления)

Если у сервиса задан `callback_url` **и** у ссылки непустой
`callback_data`, то после **каждого** клика сервер шлёт `POST` на
`callback_url` с телом из отрендеренного `callback_data`. Дополнительных
заголовков аутентификации нет.

Условия срабатывания (оба обязательны):

- у сервиса задан `Service.callback_url` (не `null`);
- у ссылки непустой `Link.callback_data`.

### Плейсхолдеры

Внутри **строковых** значений `callback_data` можно использовать
плейсхолдеры вида `{{переменная}}` — сервер подставит реальные данные
клика. Ключи и нестроковые значения не обрабатываются.

| Плейсхолдер | Описание |
|---|---|
| `{{click.id}}` | Уникальный ID клика |
| `{{click.created_at}}` | Время клика (ISO 8601, UTC) |
| `{{click.ip}}` | IP посетителя (пустая строка, если недоступен) |
| `{{click.url}}` | Целевой URL, куда был сделан редирект |
| `{{click.referrer}}` | Значение заголовка Referer (пустая строка, если нет) |
| `{{click.user_agent}}` | User-Agent посетителя (пустая строка, если нет) |
| `{{link.id}}` | ID короткой ссылки |
| `{{link.code}}` | Код короткой ссылки (напр. `AbC12345`) |
| `{{link.title}}` | Заголовок ссылки (пустая строка, если не задан) |

Пример — ссылка с `callback_data`:

```json
{
  "callback_data": {
    "campaign_id": "cmp-42",
    "click_id": "{{click.id}}",
    "timestamp": "{{click.created_at}}",
    "source_ip": "{{click.ip}}",
    "meta": { "referrer": "{{click.referrer}}" }
  }
}
```

Тело колбека, отправленное после клика:

```json
{
  "campaign_id": "cmp-42",
  "click_id": "1337",
  "timestamp": "2026-04-21T14:05:00+00:00",
  "source_ip": "203.0.113.42",
  "meta": { "referrer": "https://t.me/channel" }
}
```

### Гарантии доставки

- До **5 попыток** с паузами между ними: 1м → 5м → 15м → 1ч.
- Успех — ответ HTTP `2xx`.
- Редиректы **не** выполняются; `3xx` или `4xx` — постоянная ошибка (без ретрая).
- `5xx` либо ошибка соединения/таймаут — ретрай.
- После исчерпания попыток колбек помечается `failed`.
- Сервер сохраняет `response_code` и `response_body` (обрезается до 10 000
  символов) для каждой финальной попытки.

---

## 11. Ошибки

| Код | Когда | Тело |
|---|---|---|
| `401` | Нет токена или он невалиден | `{ "message": "Unauthenticated." }` |
| `403` | У токена нет способности `links:create` | `{ "message": "Invalid ability provided." }` |
| `404` | Запрос к API не на техническом хосте | стандартная страница 404 |
| `422` | Ошибка валидации | `{ "message": "...", "errors": { "<поле>": ["..."] } }` |
| `429` | Превышен лимит запросов | — |

`422` возвращает `message` (текст первой ошибки, не фиксированная строка)
и `errors` — карту «поле → список сообщений»:

```json
{
  "message": "The rules field is required.",
  "errors": {
    "rules.0.condition.data.before": [
      "The rules.0.condition.data.before field is required."
    ]
  }
}
```

---

## 12. Лимиты запросов

Создание ссылок ограничено **120 запросами в минуту на сервис** (счётчик
по сервису-владельцу токена, а не по IP). Чтение справочника
(`/api/domains`, `/api/domain-groups`) лимитируется **отдельным** счётчиком
— тоже 120 запросов в минуту на сервис, поэтому чтение не расходует бюджет
создания ссылок. Каждый ответ несёт заголовки:

```http
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 119
```

При исчерпании лимита — `429 Too Many Requests`. Рекомендуется повторять
запрос только при сетевых ошибках и `5xx`; на `4xx` (включая `422` и
`429` без ожидания) — не ретраить вслепую.

---

## 13. Примеры

### curl — полный запрос

```bash
curl -sS -X POST https://brevity.example.com/api/links \
  -H "Authorization: Bearer <ваш-токен>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "short.example.com",
    "title": "Кампания весна-2026",
    "forward_query": true,
    "callback_data": {
      "campaign_id": "cmp-42",
      "click_id": "{{click.id}}"
    },
    "rules": [
      {
        "url": "https://example.com/sale",
        "condition": {
          "type": "time_before",
          "data": { "before": "2026-03-05T10:00:00+00:00" }
        },
        "transition_mode": "delayed"
      },
      { "url": "https://example.com/home" }
    ]
  }'
```

### PHP (Laravel HTTP-клиент)

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken($token)
    ->acceptJson()
    ->post('https://brevity.example.com/api/links', [
        'domain' => 'short.example.com',
        'title' => 'Кампания весна-2026',
        'rules' => [
            ['url' => 'https://example.com/home'],
        ],
    ]);

if ($response->created()) {
    $shortUrl = $response->json('data.url');
}
```

### JavaScript (fetch)

```js
const res = await fetch("https://brevity.example.com/api/links", {
  method: "POST",
  headers: {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    domain: "short.example.com",
    rules: [{ url: "https://example.com/home" }],
  }),
});

if (res.status === 201) {
  const { data } = await res.json();
  console.log(data.url);
}
```

---

## 14. Частые ошибки

- **404 на каждый запрос** — вы стучитесь не на технический хост, а на
  домен короткой ссылки. Проверьте базовый URL (см. §2).
- **403 при наличии токена** — у токена нет способности `links:create`.
  Перевыпустите токен из админки.
- **422 на `domain`** — домен не заведён в справочнике. Заведите его в
  админке (**Основное → Домены**) или не передавайте `domain`, чтобы
  использовать домен по умолчанию.
- **422 на `rules.*.url`** — URL не `http`/`https`, длиннее 2000 байт или
  не проходит валидацию формата.
- **Ошибки приходят как HTML** — не передан `Accept: application/json`.

---

## 15. Рекомендации для авторов SDK

Минимальный публичный контракт клиента:

- `createLink(CreateLinkRequest $request): CreateLinkResponse`

Рекомендуемые DTO: `CreateLinkRequest`, `CreateLinkRule`,
`CreateLinkCondition`, `CreateLinkResponse`, `CreateLinkResponseRule`.

Рекомендуемые исключения:

- `AuthenticationException` (HTTP 401);
- `ValidationException` (HTTP 422, с `errors` как «поле → сообщения[]»);
- `ApiException` (прочие 4xx/5xx);
- `TransportException` (таймаут/сеть).

Технические практики:

- Таймаут запроса по умолчанию — 5–10 секунд.
- Ретраить только сетевые ошибки и `5xx` (не ретраить `4xx`).
- Всегда слать `Accept: application/json`.
- Не преобразовывать `condition.data`, кроме JSON-сериализации.

---

## 16. Готовые тестовые payload'ы

Валидный `time_before`:

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "condition": {
        "type": "time_before",
        "data": { "before": "2026-03-05T10:00:00+00:00" }
      }
    }
  ]
}
```

Валидный отложенный переход:

```json
{
  "rules": [
    { "url": "https://example.com/redirect", "transition_mode": "delayed" }
  ]
}
```

Невалидный `time_before` (неверный формат даты) — ждём `422`:

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "condition": {
        "type": "time_before",
        "data": { "before": "2026-03-05 10:00:00" }
      }
    }
  ]
}
```

Невалидный `time_before` (нет обязательного поля) — ждём `422`:

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "condition": { "type": "time_before", "data": {} }
    }
  ]
}
```

---

## 17. Чеклист интеграции

- [ ] Сервис создан в админке.
- [ ] Выпущен токен со способностью `links:create`, сохранён в секретах.
- [ ] Базовый URL указывает на технический хост.
- [ ] Все запросы шлют `Authorization`, `Accept` и `Content-Type`.
- [ ] Нужный домен заведён в справочнике (или используется домен по умолчанию).
- [ ] Обрабатываются `401/403/422/429`.
- [ ] Для колбеков: у сервиса задан `callback_url`, у ссылок — `callback_data`.
- [ ] Учтён лимит 120 запросов/мин на сервис.
