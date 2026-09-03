# API Foundation — Developer Guide

**Module:** API Foundation (Phase A + C)
**Status:** Shipped 2026-06-22
**Relevant files:**

| Path | Purpose |
|---|---|
| `routes/api.php` | All `/api/v1/` route definitions |
| `config/api.php` | TTL, token ability sets |
| `config/sanctum.php` | Sanctum guard configuration |
| `app/Http/Controllers/Api/V1/ApiController.php` | Base controller — `success()` / `error()` helpers |
| `app/Http/Controllers/Api/V1/ConfigController.php` | `GET /api/v1/config` |
| `app/Http/Controllers/Api/V1/Auth/LoginController.php` | `POST /api/v1/auth/login` |
| `app/Http/Controllers/Api/V1/Auth/LogoutController.php` | `POST /api/v1/auth/logout` |
| `app/Http/Controllers/Api/V1/Auth/MeController.php` | `GET /api/v1/auth/me` |
| `app/Models/User.php` | `HasApiTokens` trait added |
| `app/Providers/AppServiceProvider.php` | Rate limiter registration |
| `tests/Feature/Api/V1/ConfigEndpointTest.php` | Config endpoint tests |
| `tests/Feature/Api/V1/Auth/AuthEndpointsTest.php` | Auth endpoint tests |

---

## Architecture

All API routes live under `/api/v1/` (versioned from day one). `bootstrap/app.php` registers `routes/api.php` as the `api` routing file — this was wired automatically by `php artisan install:api`.

### Response envelope

Every response from this API follows a consistent shape:

```json
// Success
{ "data": { ... }, "meta": { ... } }

// Error (also matches Laravel validation error shape)
{ "message": "...", "errors": { "field": ["msg"] } }
```

The `meta` key is optional — omitted when empty. All controllers extend `ApiController` and call `$this->success(array $data, array $meta = [], int $status)` or `$this->error(string $message, int $status)`.

### Authentication

Laravel Sanctum token auth. Three token scopes:

| Scope | Issued by | Used for |
|---|---|---|
| `frontend:*` | `POST /api/v1/auth/login` | React/Next.js authenticated users |
| `patient:*` | (future: patient portal flow) | Patient self-service |
| `integration:*` | Filament admin token manager (future) | 3rd-party CRM/webhook integrations |
| `admin:*` | Manual issuance | Server-to-server admin tooling |

Token abilities are documented in `config/api.php` under `token_abilities`.

### Rate limiting

Registered in `AppServiceProvider::configureRateLimiters()`:

| Limiter | Route group | Limit |
|---|---|---|
| `auth` | `/api/v1/auth/*` | 10 req/min per IP |
| `api` | All authenticated routes | 120 req/min per user (IP fallback) |

---

## Endpoints

### `GET /api/v1/config` — Public bootstrap

No authentication required. Cached for 5 minutes (`API_CONFIG_TTL` env var, default 300s).

**Purpose:** Single call for the React frontend on startup. Returns everything needed to render the app shell without additional requests.

**Response:**

```json
{
  "data": {
    "brand": {
      "name": "Acme Health",
      "tagline": "Your health, simplified.",
      "logo_url": "https://cdn.example.com/logo.png",
      "favicon_url": "https://cdn.example.com/favicon.ico",
      "hero_image_url": null,
      "announcement": {
        "emphasis": "New!",
        "text": "Free consultations this week."
      }
    },
    "theme": {
      "primary_color": "#2563eb",
      "accent_color": "#7c3aed",
      "accent_secondary_color": "#059669",
      "background_color": "#ffffff",
      "text_color": "#111827",
      "font_display": "Inter",
      "font_body": "Inter"
    },
    "contact": {
      "support_email": "support@example.com",
      "phone": "+1 555-000-0000",
      "social": {
        "instagram": "https://instagram.com/example"
      }
    },
    "seo": {
      "default_title": "Acme Health | Telehealth",
      "default_description": "...",
      "og_image_url": null,
      "allow_indexing": true
    },
    "provider": {
      "name": "PrescribeRx",
      "slug": "prescribe-rx",
      "supports_embed": true,
      "supports_patient_portal_auth": false
    }
  }
}
```

**Cache invalidation:** every `Update*SettingsAction` calls `Cache::forget('api.v1.config')` after save, so admin edits surface on the frontend's next boot call without waiting out the TTL.

### `POST /api/v1/auth/login`

Public. Rate-limited to 10/min per IP.

**Body:** `{ "email", "password", "device_name?" }`

**Returns:** `{ "data": { "token", "token_type": "Bearer", "user": { id, name, email } } }`

**Errors:**
- `422` — wrong credentials or validation failure
- `403` — account deactivated (`is_active = false`)

### `POST /api/v1/auth/logout`

Requires: `Authorization: Bearer {token}`

Revokes the **current token only**. Other tokens for the same user remain valid (useful when a user is logged in on multiple devices).

### `GET /api/v1/auth/me`

Requires: `Authorization: Bearer {token}`

Returns: `{ id, name, email, roles[], last_login_at }`

---

## Adding new endpoints

1. Create controller in `app/Http/Controllers/Api/V1/{Module}/`. Extend `ApiController`.
2. Register the route in `routes/api.php` inside the appropriate middleware group.
3. Add tests in `tests/Feature/Api/V1/{Module}/`.
4. Run `vendor/bin/pint --dirty --format agent`.

For modules that need token ability checks, use `$request->user()->tokenCan('patient:read')` or middleware `abilities:patient:read` (Sanctum middleware).

---

## Token ability middleware

Sanctum ships two middleware you can apply to routes:

```php
// Single ability required
Route::get('/prescriptions', ...)->middleware('ability:patient:read');

// Any one of these abilities
Route::get('/prescriptions', ...)->middleware('abilities:patient:read,frontend:*');
```

Register these in `bootstrap/app.php` under `->withMiddleware()` if needed:

```php
$middleware->alias([
    'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
]);
```

## Trusted proxies — who the app believes the visitor is

`$request->ip()` is not decoration here. It is written to `leads.ip_address`,
`carts.ip_address`, and **`lead_consents.ip_address`** — the last being the
record of *who* agreed to be contacted, which is the evidence a TCPA or
CAN-SPAM question is answered with. `$request->userAgent()` accompanies it in
all three. The `api` rate limiter keys on the same address:
`Limit::perMinute(120)->by($request->user()?->id ?? $request->ip())`.

**Put a reverse proxy in front with nothing trusted here and all of that
silently becomes the proxy.** Nothing errors. The rows still write and the
limiter still limits; they just describe the wrong party, and every visitor
collapses into one bucket because they now share one address.

### Configuring it

`config/trustedproxy.php` is read natively by Laravel's `TrustProxies`
middleware — there is no provider or middleware wiring to do. One env var:

```dotenv
TRUSTED_PROXIES=                        # empty: trust nothing
TRUSTED_PROXIES=127.0.0.1               # app behind a local Apache/nginx
TRUSTED_PROXIES=REMOTE_ADDR             # behind a load balancer with no fixed address
TRUSTED_PROXIES=10.0.1.0/24,10.0.2.0/24 # known subnets
```

Laravel believes `X-Forwarded-*` **only when the connecting peer matches**. A
forged header from the open internet is ignored, because the sender's own
address is not on the list.

### Do not use `*`

It reads as "trust any proxy". It means
`setTrustedProxies(['0.0.0.0/0', '::/0'])` — *every* address is a trusted
proxy, so Symfony strips the entire `X-Forwarded-For` chain as trusted and
falls back to its **leftmost** entry, which is precisely the part the client
writes. Measured against the real middleware:

```
peer = 10.0.1.20 (ALB ENI)   X-Forwarded-For: "9.9.9.9, 203.0.113.55"
  *              ->  9.9.9.9        forged by the visitor
  REMOTE_ADDR    ->  203.0.113.55   correct
  10.0.1.0/24    ->  203.0.113.55   correct
```

An appending proxy (an ALB in its default mode, Apache, nginx) puts the true
client on the **right**. With `*`, anyone can prepend an address and have it
recorded as the consenting party, and rotate it to defeat the rate limiter —
the exact failures this setting exists to prevent.

### Behind a load balancer (AWS ALB, GCP, Azure)

**An ARN is a control-plane identifier and never appears on the wire, and the
balancer needs no public IP for any of this.** What matters is the address of
the **connecting peer**, which for an ALB is the private address of its ENI in
your subnet. Those churn and cannot be enumerated, which is why naming them is
not an option.

```dotenv
TRUSTED_PROXIES=REMOTE_ADDR
```

`REMOTE_ADDR` is a keyword Laravel substitutes for the actual peer, so it
trusts exactly whoever connected — whatever address that turns out to be. **It
is safe for one specific reason:** the target's security group admits traffic
only from the load balancer's security group, so nothing else can ever be the
peer. The network enforces what an address list otherwise would. **Treat that
ingress rule and this setting as one decision** — widen the SG, or share it
with another workload, and this stops being safe the same day.

Prefer subnet CIDRs when the balancer's subnets are known and stable; it is the
same guarantee without depending on the SG.

**A second, public-facing load balancer is not needed and would not help.** The
problem was never that the balancer lacks a public address; it is only ever
about which peer to believe.

### A layer-4 balancer is the "trust nothing" case

An AWS NLB adds no `X-Forwarded-For` at all, and with client-IP preservation
the visitor is already the peer. Trusting anything there means believing a
header the visitor wrote. Leave `TRUSTED_PROXIES` empty.

### More than one proxy in the chain

The header is a list and each hop appends, so a CDN in front of a balancer
arrives as `<visitor>, <cdn edge>`. Symfony walks that list from the right,
discarding entries that match trusted proxies, and reports the first that does
not. **Every** hop in front must therefore be trusted, or it stops early and
reports the CDN. `REMOTE_ADDR` alone trusts one hop; add the CDN's published
ranges for two.

The reference storefront's proxy takes a different and deliberate approach: it
replaces the header with a **single** value — the rightmost entry of what it
received — so anything a visitor prepended is discarded before this app sees
it. That is a second line of defence, not a substitute for configuring this
correctly. Note also that if a CDN is placed in front of *that* storefront, its
own rightmost-entry extraction has to change; this app's config does not.

### Verifying it, rather than assuming

From a host whose address is trusted:

```bash
curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.55' https://<host>/api/v1/cart
# read the row back — ip_address must be 203.0.113.55
```

Then repeat from an **untrusted** host. The forged header must be ignored. If
both give the forged value, the trust list is too wide and anyone can write
whatever they like into a consent record.

## Scheduled tasks

`routes/console.php` holds them, and they need `schedule:run` on a per-minute
cron. **Without the cron nothing here runs and nothing says so** — the app keeps
serving and the work silently never happens. This install ran that way until
2026-09-03: a scheduler cron existed for other apps on the box but never for
this one, and no task had ever been defined either, so a cron alone would have
run nothing.

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> storage/logs/scheduler-$(date +\%Y-\%m-\%d).log 2>&1
0 3 * * * find /path/to/app/storage/logs -name 'scheduler-*.log' -mtime +14 -delete 2>/dev/null
```

**QUEUED WORK DOES NOT DEPEND ON THIS, and conflating the two wastes a
diagnosis.** Jobs, workflows, integration pushes and cache revalidation are
processed by Horizon under supervisor. The scheduler covers only time-based
recurring work. "Automations aren't running" is a Horizon question first.

| Task | When | Why |
|---|---|---|
| `horizon:snapshot` | every 5 min | Horizon's Metrics tab is built entirely from these. Without it the dashboard is permanently empty — no throughput, no runtime trends, no queue visibility at all. |
| `queue:prune-failed --hours=168` | weekly | `failed_jobs` grows without bound. A week is long enough to investigate, short enough that the table never becomes the problem. |
| `queue:prune-batches --hours=168` | weekly | Same, for batch bookkeeping. |
| `model:prune --model=…\Cart` | daily 03:20 | Abandoned carts, 90 days. See below. |
| `sanctum:prune-expired --hours=24` | daily | Harmless until tokens exist; required the moment the API moves behind Sanctum, since an expired token still listed in the admin looks live. |

Every task is `onOneServer()`. This box hosts several Laravel apps and may one
day be more than one box; a duplicate prune or snapshot is wasteful at best.

`model:prune` is called with an **explicit `--model`**. With no argument it
discovers every Prunable model in the app, which would quietly widen the blast
radius the day someone adds the trait somewhere else.

### Cart pruning, and the guard that matters

Every anonymous visitor mints a cart row, so the table grows with traffic and
nothing else removes it. `expires_at` already governs whether a cart is
*usable* — the cart endpoint treats an expired one as absent and mints a fresh
one — so what was missing was only the reaping.

**90 days, measured from `updated_at`**, so a cart someone is still touching is
never in scope however old the row is.

**A cart referenced by a lead is never pruned.** The checkout endpoint resolves
the cart by ulid with `firstOrFail()` and `hash_equals()` it against
`leads.cart_ulid` to prove cart and lead came from the same visitor. Reap one of
those and that checkout 404s — and the reference is held remotely too, since the
telehealth provider stores the lead uuid and returns it on its webhook. The
guard is mutation-tested.

**Attribution is not at risk today, and `Cart::prunable()` is the line to
revisit when it is.** utm/referrer/landing_url live on `leads`, which nothing
prunes, so a reaped cart destroys no marketing data. When a cart starts carrying
a referral of its own, it needs the same style of "not referenced" guard: an
unconverted click reaped at 90 days is a commission nobody can reconcile.
