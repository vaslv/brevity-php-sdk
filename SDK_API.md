# Brevity API Contract (for SDK Development)

This document describes the current public API contract required to build a client SDK.

## 1. General Information

- Base URL: `https://<your-host>`
- Data format: `application/json`
- API versioning: none (current path: `/api/...`)
- Authentication: `Bearer` token (Laravel Sanctum personal access token)

Recommended SDK headers:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <token>
```

## 2. Endpoints

### `POST /api/links`

Creates a short link and transition rules.

#### Request body

```json
{
  "domain": "short.example.com",
  "title": "Campaign link",
  "forward_query": true,
  "callback_data": {
    "campaign_id": "cmp-42"
  },
  "rules": [
    {
      "url": "https://example.com/landing?b=2&a=1",
      "condition": {
        "type": "time_before",
        "data": {
          "before": "2026-03-05T10:00:00+00:00"
        }
      },
      "transition_mode": "delayed"
    }
  ]
}
```

#### Request fields

- `domain` (`string|null`, max 255): short-link domain; must exist in the system (`exists:domains,value`).
- `title` (`string|null`): link title.
- `forward_query` (`boolean|null`): whether to forward query parameters on direct HTTP redirect.
- `callback_data` (`object|null`): arbitrary callback payload.
- `rules` (`array`, required, min 1): transition rules in priority order.
- `rules[].url` (`string`, required, url, max 2048): destination URL.
- `rules[].condition` (`object|null`): condition for applying the rule.
- `rules[].condition.type` (`string`, required if `condition` is present): condition type.
- `rules[].condition.data` (`object|null`): condition payload.
- `rules[].transition_mode` (`string|null`): transition mode (`direct`, `delayed`, `manual`).

#### Supported condition types

Currently registered:

1. `time_before`
   - `data.before` (`string`, required)
   - format: `Y-m-d\TH:i:sP` (example: `2026-03-05T10:00:00+00:00`)

Important: validation for `rules[].condition.data` is dynamic and resolved from `ConditionHandler::rules()` based on `condition.type`.

#### Supported transition modes

1. `direct` (default)
   - Server returns a standard HTTP redirect response.
2. `delayed`
   - Server returns an HTML page with destination info and automatic redirect countdown.
   - Countdown duration is currently fixed to 5 seconds.
3. `manual`
   - Server returns an HTML page with destination info and a manual continue button.

## 3. Server Behavior

- Rule priority is defined by array order in `rules` (first item = highest priority).
- `rules[].url` is normalized by the server (URL normalization + query sorting).
- If a condition with the same `type` and identical `data` already exists, the server reuses it.
- On success, the endpoint returns `201 Created`.

## 4. Responses

### 201 Created

```json
{
  "data": {
    "url": "https://short.example.com/AbC12345",
    "domain": "short.example.com",
    "code": "AbC12345",
    "title": "Campaign link",
    "forward_query": true,
    "callback_data": {
      "campaign_id": "cmp-42"
    },
    "rules": [
      {
        "url": "https://example.com/landing?a=1&b=2",
        "condition": {
          "type": "time_before",
          "data": {
            "before": "2026-03-05T10:00:00+00:00"
          }
        },
        "transition_mode": "delayed"
      }
    ]
  }
}
```

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

### 422 Unprocessable Entity (validation error)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "rules.0.condition.data.before": [
      "The rules.0.condition.data.before field is required."
    ]
  }
}
```

## 5. SDK Recommendations

Minimum public client contract:

- `createLink(CreateLinkRequest $request): CreateLinkResponse`

Recommended DTOs:

- `CreateLinkRequest`
- `CreateLinkRule`
- `CreateLinkCondition`
- `CreateLinkResponse`
- `CreateLinkResponseRule`

Recommended SDK exceptions:

- `AuthenticationException` (HTTP 401)
- `ValidationException` (HTTP 422, with `errors` as `field -> messages[]`)
- `ApiException` (other 4xx/5xx)
- `TransportException` (timeout/network)

Recommended SDK technical practices:

- Default request timeout: 5-10 seconds.
- Retry only network/5xx failures (no retry for 4xx).
- Always send `Accept: application/json`.
- Do not transform `condition.data` except JSON serialization.

## 6. Ready-to-Use SDK Test Payloads

### Valid `time_before`

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "condition": {
        "type": "time_before",
        "data": {
          "before": "2026-03-05T10:00:00+00:00"
        }
      }
    }
  ]
}
```

### Valid delayed transition

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "transition_mode": "delayed"
    }
  ]
}
```

### Invalid `time_before` (invalid date format)

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "condition": {
        "type": "time_before",
        "data": {
          "before": "2026-03-05 10:00:00"
        }
      }
    }
  ]
}
```

### Invalid `time_before` (missing required field)

```json
{
  "rules": [
    {
      "url": "https://example.com/redirect",
      "condition": {
        "type": "time_before",
        "data": {}
      }
    }
  ]
}
```
