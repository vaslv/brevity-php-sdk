# Working with the API

A practical guide for those integrating their system with Brevity:
how to obtain an API token, authenticate, and create short links.

> The single source of truth for the HTTP API: how to get started, the
> full list of fields, validation rules, callbacks, and ready-made test
> payloads for SDK authors. For terminology, see
> [02-glossary.md](https://github.com/vaslv/brevity/blob/main/docs/02-glossary.md).

---

## 1. What you will need

To call the API, you need two things:

1. A **Service** (`Service`) — a record in the system that owns links
   and receives callbacks. Created in the admin panel (the **Main →
   Services** section).
2. An **API token** of that service with the `links:create` ability.

The token "represents" the service: all links created with this token
are tied to the owning service, and limits are counted per service.

### How to obtain a token

The token is issued from the admin panel; there is no programmatic
endpoint for this:

1. Open the service: **Main → Services → \<the service you need\>**.
2. Click **"Create token"** (the button with the key icon in the
   header).
3. Optionally pick an expiration period (30 / 90 / 365 days). Without
   a selection the token is perpetual.
4. Copy the displayed token **immediately** — it is shown once and is
   never available in plain text again.

The token automatically receives the `links:create`, `links:read`, and
`links:update` abilities — exactly what the `/api/v1` surface needs
(the principle of least privilege). Tokens issued earlier with only
`links:create` keep creating links; to read/update, reissue the token.
Expired tokens are periodically purged by the scheduled
`sanctum:prune-expired` command.

Token format: `<id>|<prefix><random-part>`. The prefix exists for
secret-leak scanners; pass the whole string into the `Authorization`
header exactly as the admin panel showed it.

---

## 2. Base URL and host

> ⚠️ **Important.** The API is available **only on the technical
> host** — the same host as `APP_URL` (for example,
> `https://brevity.example.com`). Short-link domains
> (`short.example.com` and the like) serve redirects only; a request to
> `/api/...` on them returns **404**, even before the token and limits
> are checked.

```
Base URL:      https://<technical-host>
Paths:         /api/v1/links, /api/v1/domains, /api/v1/domain-groups
Versioning:    v1 in the path
Data format:   application/json (v1 errors — application/problem+json)
```

> **Legacy.** The old unversioned paths (`/api/links`, `/api/domains`,
> `/api/domain-groups`) keep working with the previous error format,
> but are **deprecated**: they are not documented and give no
> guarantees for new capabilities — the contract is fixed only for
> `/api/v1` (errors — RFC 7807, see §11). Move your integrations to
> `/api/v1`.

The technical host is configured via `APP_TECHNICAL_HOST` (by default
it is taken from the host of `APP_URL`). Ask your administrator for the
current value for your environment.

---

## 3. Authentication

Pass the token in the `Authorization` header using the Bearer scheme.
The recommended set of headers for any request:

```http
Authorization: Bearer <your-token>
Accept: application/json
Content-Type: application/json
```

- `Accept: application/json` is mandatory — otherwise validation errors
  may come back as an HTML redirect instead of JSON.
- The token must carry the `links:create` ability. Tokens with the `*`
  wildcard are also accepted (backward compatibility).
- No token / invalid token → `401`. Token present but missing the
  required ability → `403`.

---

## 4. Quick start

The minimal request — one rule with one target URL:

```bash
curl -sS -X POST https://brevity.example.com/api/v1/links \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "rules": [
      { "url": "https://example.com/landing" }
    ]
  }'
```

Response `201 Created`:

```json
{
  "data": {
    "url": "https://short.example.com/AbC12345",
    "domain": "short.example.com",
    "code": "AbC12345",
    "title": null,
    "forward_query": false,
    "callback_data": null,
    "valid_since": null,
    "valid_until": null,
    "max_clicks": null,
    "rules": [
      {
        "url": "https://example.com/landing",
        "conditions": [],
        "condition": null,
        "variants": [],
        "transition_mode": null
      }
    ]
  }
}
```

The ready-to-use short link is in the `data.url` field.

---

## 5. Creating a link: `POST /api/v1/links`

Creates a short link and its transition rules in a single request.

### Request body (overview)

| Field | Type | Req. | Notes |
|---|---|:---:|---|
| `domain` | string\|null | no | Explicit short-link host. Must exist in the dictionary. Mutually exclusive with `domain_strategy`/`domain_group`. Empty and no strategy → the default domain. |
| `domain_strategy` | string\|null | no | Automatic domain selection: `random` / `round_robin` / `coldest`. Required when `domain_group` is present. See §8. |
| `domain_group` | string\|null | no | Group code — restrict the selection to a group of domains. Without it — across all domains. |
| `title` | string\|null | no | Link title, up to 64 characters. |
| `forward_query` | bool\|null | no | Whether to forward query parameters on a direct redirect. |
| `callback_data` | object\|null | no | Callback payload template (up to 50 keys). See §10. |
| `valid_since` | string\|null | no | Start of the activity window, `Y-m-d\TH:i:sP`. Before this moment the link responds with 404 (no click and no callback). |
| `valid_until` | string\|null | no | End of the activity window (not earlier than `valid_since`; boundaries inclusive). Afterwards — 404. |
| `max_clicks` | int\|null | no | Click limit (≥ 1; **all** clicks count, including bots). Once reached — 404. Because clicks are recorded asynchronously, the limit may be exceeded by a few clicks during a traffic spike. |
| `rules` | array | **yes** | Transition rules, 1 to 50, in priority order. |
| `rules[].url` | string | **yes** | Target URL (`http`/`https`), up to 2000 bytes. |
| `rules[].conditions` | array\|null | no | Trigger conditions (up to 10). A rule matches when **all** of them match (AND); an empty list — an unconditional rule. |
| `rules[].conditions[].type` | string | in each condition | Condition type (see §6). |
| `rules[].conditions[].data` | object\|null | no | Condition data; validated according to the type. |
| `rules[].condition` | object\|null | no | **Deprecated.** A single condition; collapsed into `conditions[0]`. Do not combine with `conditions`. |
| `rules[].variants` | array\|null | no | A/B split: 2–20 weighted targets `{ url, weight, label? }`, `weight` — an integer 1..1000. Without `variants` the rule leads to `rules[].url`. See §7.1. |
| `rules[].transition_mode` | string\|null | no | Transition mode: `direct` / `delayed` / `manual`. |

### How the server processes the request

- **Rule priority** is determined by the order in the `rules` array:
  the first element has the highest priority. When resolving a link,
  the server takes the first rule whose conditions are **all** true (a
  rule without conditions is always true — it is usually placed last as
  a fallback).
- **The target URL is normalized** on the server (normalization +
  query-parameter sorting), so `rules[].url` in the response may differ
  from what was sent in the order of parameters.
- **Conditions are deduplicated**: identical `(type, data)` pairs reuse
  a single `Condition` record.
- On success — `201 Created` with the link body.

---

## 5.1. Reading a link: `GET /api/v1/links/{code}`

Returns the state of a link **belonging to your service**: the same
shape as the creation response, plus a click summary from the
pre-aggregated counters. Requires the `links:read` ability.

```bash
curl -sS https://brevity.example.com/api/v1/links/AbC12345 \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json"
```

Response `200 OK` — the creation fields plus a `clicks` block:

```json
{
  "data": {
    "url": "https://short.example.com/AbC12345",
    "code": "AbC12345",
    "valid_since": null,
    "valid_until": "2026-09-01T00:00:00+00:00",
    "max_clicks": 100,
    "clicks": { "total": 42, "non_bots": 37 },
    "rules": [ { "url": "https://example.com/landing", "conditions": [], "condition": null, "variants": [], "transition_mode": null } ]
  }
}
```

- A code of another service, a non-existent or a deleted one — always
  `404` `not-found`: the existence of other services' codes is not
  disclosed.
- `clicks` is computed from the counters and may lag behind reality by
  seconds (clicks are recorded asynchronously).

---

## 5.2. Updating a link: `PATCH /api/v1/links/{code}`

Partial update of a link **belonging to your service**. Requires the
`links:update` ability. PATCH semantics:

- **A field that is not passed does not change.** Send only what you
  want to change.
- **An explicit `null` clears the value** — this is how you remove the
  limit (`max_clicks: null`) or the window (`valid_until: null`).
- Editable fields: `title`, `forward_query`, `callback_data`,
  `valid_since`, `valid_until`, `max_clicks`, `rules`.
- **Immutable**: `code`, `domain`, the owning service. These keys in
  the body are ignored.
- `rules`, if passed, **replaces the whole rule set entirely** (the
  same constraints as at creation: 1–50, order = priority).
- The resulting window is validated against the merged state: if,
  after applying the patch, `valid_until` would end up earlier than
  `valid_since` (including stored values) — `422 validation-error`.

```bash
curl -sS -X PATCH https://brevity.example.com/api/v1/links/AbC12345 \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{ "max_clicks": null, "valid_until": "2026-12-31T23:59:59+00:00" }'
```

Response `200 OK` — the same shape as at creation. A code of another
service or a non-existent one — `404 not-found`.

---

## 6. Conditions (`condition`)

A condition makes a rule selective. If the condition is true, this rule
is applied; otherwise the server tries the next one by priority.

The current set of types:

| `type` | Purpose | `data` |
|---|---|---|
| `time_before` | Fires while the current time is **before** the specified one | `{ "before": "<ISO 8601>" }` |
| `after_date` | Fires once the current time has **reached** the specified one (inclusive) | `{ "after": "<ISO 8601>" }` |
| `query_param` | Fires on an exact `key=value` match of the visit's query parameter | `{ "key": "partner", "value": "acme" }` |
| `ip_address` | Fires on the visitor's IP: exact / CIDR / IPv4 wildcard | `{ "ip": "10.0.0.0/24" }` |
| `device` | Fires on the device type from the User-Agent. One device matches several types (iPhone = `ios` AND `mobile`) | `{ "device": "mobile" }` |

`device` types: `android`, `ios`, `mobile`, `windows`, `macos`,
`linux`, `chromeos`, `desktop`.

| `type` | Purpose | `data` |
|---|---|---|
| `language` | Fires when `Accept-Language` confidently (quality ≥ 0.9) prefers the language; optionally with an exact country | `{ "language": "en", "country": "US" }` |

`language` — ISO 639 (2–3 letters); `country` (optional) — ISO 3166-1
alpha-2. Without `country` the match is by language; with `country` —
an exact `language-country` match. An empty header or `*` does not
match.

The `before`/`after` format is `Y-m-d\TH:i:sP`, for example
`2026-03-05T10:00:00+00:00`. The field is required for its type. The
`after_date` + `time_before` pair in one rule (RUL-01) defines a
landing page's activity window with no gap at the shared boundary.

Example: before March 5, 2026 — to the promo landing page, afterwards —
to the regular one:

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

The list of types is extensible (via `ConditionHandler` in
`ConditionRegistry`). For the up-to-date list and the `data` shape of
each type, see [02-glossary.md](https://github.com/vaslv/brevity/blob/main/docs/02-glossary.md).

---

## 7. Transition modes (`transition_mode`)

How the server responds to the visitor when a rule fires:

| Value | Behavior |
|---|---|
| `direct` (or `null`) | HTTP redirect (302). The default value. |
| `delayed` | An HTML page with an automatic redirect after a countdown. |
| `manual` | An HTML page with a "continue" button. |

If the field is not passed, it is `null` in the response, which is
equivalent to `direct`.

---

## 7.1. A/B split rules

A rule can be given `variants` — 2–20 weighted target URLs. When the
rule wins, the server picks a variant by weights:

```json
{
  "rules": [
    {
      "url": "https://example.com/control",
      "variants": [
        { "url": "https://example.com/a", "weight": 1, "label": "A" },
        { "url": "https://example.com/b", "weight": 3, "label": "B" }
      ]
    }
  ]
}
```

- **Weights** are integers from 1 to 1000; a variant's traffic share =
  `weight / sum of weights` (in the example B gets 75 %). They do not
  have to add up to 100.
- **Sticky:** the choice is deterministic by `(IP, User-Agent, link)` —
  one visitor consistently lands on the same variant.
- The `label` (optional, up to 64 characters) is returned in the
  callback via `{{click.variant}}` — the partner sees which variant
  converted.
- Without `variants` the rule leads to its own `url` (backward
  compatibility).
- `rules[].url` is always required — it is also the fallback if the
  variants are removed later.

## 8. Domains

The server determines the link's domain in this order:

1. **An explicit `domain`** — the short link is built on it. The domain
   must exist in the dictionary (otherwise `422`).
2. **`domain_strategy`** (if set) — the domain is selected
   automatically according to the strategy, see below.
3. **Neither a domain nor a strategy** — the domain marked as the
   default is used.
4. **No default domain either** — the link is left without a domain and
   resolves via `APP_URL` (the `domain` field in the response will be
   `null`).

The `data.url` field in the response always contains the final short
link with the domain already substituted.

### Automatic domain selection by strategy

To get a link on a **non-default** domain without naming a specific
domain, pass `domain_strategy`. The selection runs over a pool: the
group's domains (if `domain_group` — a group code — is set) or all
domains.

| Strategy | How it chooses |
|---|---|
| `random` | A random domain from the pool. |
| `round_robin` | The least recently used one — domains go in a circle, each next link gets the next domain. |
| `coldest` | The "coldest" one — with the fewest links over a period (30 days by default). |

- `domain` and `domain_strategy` cannot be passed together (`422`).
- `domain_group` without `domain_strategy` is an error (`422`).
- `domain_group` is the group's `code` (always lowercase, exact
  comparison); take the value from `GET /api/v1/domain-groups`.
- The statistics for `round_robin`/`coldest` are shared across all
  services.
- If the pool has no domains (none at all, or the group is empty) —
  `422`.

A round-robin domain from the `campaigns` group:

```bash
curl -sS -X POST https://brevity.example.com/api/v1/links \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "domain_strategy": "round_robin",
    "domain_group": "campaigns",
    "rules": [ { "url": "https://example.com/landing" } ]
  }'
```

The selected domain is returned in `data.domain` and `data.url`.

---

## 9. Dictionaries: domains and groups

To choose a domain for a link, you can fetch the list of domains and
their groups via the API. Both endpoints are read-only, require the
same token with the `links:create` ability, and return data in a `data`
wrapper.

### `GET /api/v1/domains` — list of domains

Without parameters it returns **all** domains. With the `group`
parameter (a group code) — only the domains that belong to the
specified group.

| Parameter | Type | Req. | Notes |
|---|---|:---:|---|
| `group` | string\|null | no | Group code. Must exist (otherwise `422`). Without it — all domains. |

```bash
curl -sS https://brevity.example.com/api/v1/domains \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json"
```

Response `200 OK`:

```json
{
  "data": [
    { "domain": "short.example.com", "url": "https://short.example.com", "is_default": true },
    { "domain": "go.example.com", "url": "https://go.example.com", "is_default": false }
  ]
}
```

Only the domains from the `campaigns` group:

```bash
curl -sS "https://brevity.example.com/api/v1/domains?group=campaigns" \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json"
```

Fields: `domain` — the host, `url` — the same as an `https://` address,
`is_default` — whether the domain is used by default. Sorted by
`domain`.

### `GET /api/v1/domain-groups` — list of groups

Returns all domain groups with the number of domains in each. Use the
`code` value as `group` in the domains request.

```bash
curl -sS https://brevity.example.com/api/v1/domain-groups \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json"
```

Response `200 OK`:

```json
{
  "data": [
    { "code": "primary", "name": "Primary", "domains_count": 3 },
    { "code": "campaigns", "name": "Campaigns", "domains_count": 5 }
  ]
}
```

Fields: `code` — the group code, `name` — the name, `domains_count` —
the number of domains in the group. Sorted by `name`. The code is
always lowercase; in the `?group=` filter and in the `domain_group`
field specify it exactly as in the response (the comparison is exact).

---

## 10. Callbacks (outgoing notifications)

If the service has a `callback_url` set **and** the link has a
non-`null` `callback_data`, then after **every** click the server sends
a `POST` to the `callback_url` with a body built from the rendered
`callback_data`. There are no additional authentication headers.

Trigger conditions (both are required):

- the service has `Service.callback_url` set (not `null`);
- the link has a non-empty `Link.callback_data`.

### Placeholders

Inside **string** values of `callback_data` you can use placeholders of
the form `{{variable}}` — the server substitutes real click data. Keys
and non-string values are not processed.

| Placeholder | Description |
|---|---|
| `{{click.id}}` | The unique click ID |
| `{{click.created_at}}` | Click time (ISO 8601, UTC) |
| `{{click.is_bot}}` | The bot flag as a string: `true` / `false` (see "Bot flag") |
| `{{click.ip}}` | The visitor's IP (an empty string if unavailable) |
| `{{click.url}}` | The target URL the redirect was made to |
| `{{click.referrer}}` | The value of the Referer header (an empty string if absent) |
| `{{click.user_agent}}` | The visitor's User-Agent (an empty string if absent) |
| `{{click.variant}}` | The label of the A/B variant the click landed on (an empty string if there was no split) |
| `{{click.query.<param>}}` | The value of the visit's query parameter (e.g. `{{click.query.sub_id}}`). The parameter name is taken as is — dots and hyphens are preserved (`{{click.query.sub.id}}`, `{{click.query.sub-id}}`). A missing parameter — an empty string. The name may contain only letters, digits, `_`, `.`, `-`: a parameter with other characters (a space etc.) cannot be addressed by a placeholder |
| `{{link.id}}` | The short link ID |
| `{{link.code}}` | The short link code (e.g. `AbC12345`) |
| `{{link.title}}` | The link title (an empty string if not set) |

Example — a link with `callback_data`:

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

The callback body sent after a click:

```json
{
  "campaign_id": "cmp-42",
  "click_id": "1337",
  "timestamp": "2026-04-21T14:05:00+00:00",
  "source_ip": "203.0.113.42",
  "meta": { "referrer": "https://t.me/channel" },
  "is_bot": false
}
```

### Bot flag (`is_bot`)

Every visit is classified by its User-Agent (a library of crawler
patterns), and **every** callback carries a boolean `is_bot` field at
the root of the body — regardless of whether it is present in your
`callback_data`. Callbacks for bot clicks are sent on a par with
regular ones: the decision whether to count such a visit is left to
your system. The field is also added to links created before it was
introduced — it is unconditional and requires no separate
subscription.

- The `is_bot` key at the root of `callback_data` is **reserved**: a
  client-provided value is overwritten by the server's.
- A visit without a User-Agent is considered not a bot (`false`).
- For substitution inside string values there is the
  `{{click.is_bot}}` placeholder (the strings `true` / `false`).

### Delivery guarantees

- Up to **5 attempts** with pauses between them: 1m → 5m → 15m → 1h.
- Success — an HTTP `2xx` response.
- Redirects are **not** followed; `3xx` or `4xx` is a permanent failure
  (no retry).
- `5xx` or a connection error/timeout — retry.
- Once the attempts are exhausted, the callback is marked `failed`.
- The server stores the `response_code` and `response_body` (truncated
  to 10,000 characters) for each final attempt.

---

## 11. Errors

Under `/api/v1`, every error is JSON in the RFC 7807 format
(`Content-Type: application/problem+json`) with a **stable machine
code** in the `type` field. Program your reaction against `type`, not
the `title`/`detail` texts — the texts may change.

| HTTP | `type` | When |
|---|---|---|
| `401` | `unauthenticated` | No token, or it is invalid |
| `403` | `missing-ability` | The token lacks the required ability |
| `403` | `forbidden` | The action is forbidden for another reason |
| `404` | `not-found` | The resource does not exist (or is not available to your token) |
| `422` | `validation-error` | Validation error; the `errors` field is a "field → messages[]" map |
| `429` | `too-many-requests` | The request limit is exceeded (see §12) |
| other `4xx` | `http-error` | Other HTTP errors (wrong method `405`, unsupported format, etc.) |
| `5xx` | `server-error` | Internal error (no details) |

Malformed JSON in the body is treated as empty input, so it yields
`422 validation-error` (not `http-error`).

Example `422`:

```json
{
  "type": "validation-error",
  "title": "The request failed validation.",
  "status": 422,
  "detail": "The rules field is required.",
  "errors": {
    "rules": ["The rules field is required."]
  }
}
```

A request to the API on a host other than the technical one is rejected
with `404` (see §2); for `/api/v1/*` paths — in the same
`problem+json` format (`not-found`).

**Legacy (`/api/*` without a version)** keeps the previous format:
`401/403` → `{ "message": "..." }`, `422` →
`{ "message": "<first error>", "errors": { "<field>": ["..."] } }`.

---

## 12. Request limits

There are two independent counters, each — **120 requests per minute
per service** (per the token's owning service, not per IP):

- the **write budget** — `POST /api/v1/links` and
  `PATCH /api/v1/links/{code}` (updates share the counter with
  creation);
- the **read budget** — `GET /api/v1/links/{code}` and the dictionaries
  (`/api/v1/domains`, `/api/v1/domain-groups`).

Reads and writes do not consume each other's budget. Every response
carries the headers:

```http
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 119
```

When the limit is exhausted — `429 Too Many Requests`. It is
recommended to retry a request only on network errors and `5xx`; on
`4xx` (including `422`, and `429` without waiting) — do not retry
blindly.

---

## 13. Examples

### curl — a full request

```bash
curl -sS -X POST https://brevity.example.com/api/v1/links \
  -H "Authorization: Bearer <your-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "short.example.com",
    "title": "Spring 2026 campaign",
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

### PHP (Laravel HTTP client)

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken($token)
    ->acceptJson()
    ->post('https://brevity.example.com/api/v1/links', [
        'domain' => 'short.example.com',
        'title' => 'Spring 2026 campaign',
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
const res = await fetch("https://brevity.example.com/api/v1/links", {
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

## 14. Common mistakes

- **404 on every request** — you are hitting a short-link domain
  instead of the technical host. Check the base URL (see §2).
- **403 with a token present** — the token lacks the `links:create`
  ability. Reissue the token from the admin panel.
- **422 on `domain`** — the domain is not registered in the dictionary.
  Register it in the admin panel (**Main → Domains**) or omit `domain`
  to use the default domain.
- **422 on `rules.*.url`** — the URL is not `http`/`https`, is longer
  than 2000 bytes, or fails format validation.
- **Errors come back as HTML** — `Accept: application/json` was not
  sent.

---

## 15. Recommendations for SDK authors

The minimal public client contract:

- `createLink(CreateLinkRequest $request): CreateLinkResponse`

Recommended DTOs: `CreateLinkRequest`, `CreateLinkRule`,
`CreateLinkCondition`, `CreateLinkResponse`, `CreateLinkResponseRule`.

Recommended exceptions:

- `AuthenticationException` (HTTP 401);
- `ValidationException` (HTTP 422, with `errors` as a "field →
  messages[]" map);
- `ApiException` (other 4xx/5xx);
- `TransportException` (timeout/network).

Technical practices:

- Default request timeout — 5–10 seconds.
- Retry only network errors and `5xx` (do not retry `4xx`).
- Always send `Accept: application/json`.
- Do not transform `condition.data` beyond JSON serialization.

---

## 16. Ready-made test payloads

A valid `time_before`:

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

A valid delayed transition:

```json
{
  "rules": [
    { "url": "https://example.com/redirect", "transition_mode": "delayed" }
  ]
}
```

An invalid `time_before` (wrong date format) — expect `422`:

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

An invalid `time_before` (the required field is missing) — expect
`422`:

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

## 17. Integration checklist

- [ ] The service is created in the admin panel.
- [ ] A token with the `links:create` ability is issued and stored in
      secrets.
- [ ] The base URL points to the technical host.
- [ ] All requests send `Authorization`, `Accept`, and `Content-Type`.
- [ ] The needed domain is registered in the dictionary (or the default
      domain is used).
- [ ] `401/403/422/429` are handled.
- [ ] For callbacks: the service has `callback_url` set, the links have
      `callback_data`.
- [ ] The 120 requests/min per-service limit is accounted for.
