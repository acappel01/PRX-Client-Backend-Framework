# API Foundation — Operator Guide

**Module:** API Foundation
**Audience:** Admin operators and frontend developers connecting to this backend.

---

## Overview

The prx-backend exposes a REST API at `/api/v1/`. This is how React frontends, mobile apps, and third-party integrations communicate with the platform.

---

## Authentication

The API uses **Bearer tokens** (via Laravel Sanctum). There are no cookies or sessions for the API — each request must include the token in the `Authorization` header.

### Getting a token

```
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "your-password",
  "device_name": "my-react-app"   ← optional, helps identify the token in admin
}
```

Response:

```json
{
  "data": {
    "token": "1|abc123...",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "Jane", "email": "jane@example.com" }
  }
}
```

Store this token in your frontend (e.g. `localStorage` or a cookie). Send it with every request:

```
Authorization: Bearer 1|abc123...
```

### Revoking a token (logout)

```
POST /api/v1/auth/logout
Authorization: Bearer {token}
```

This revokes only the token used for this request. Other sessions remain active.

---

## Public endpoints (no auth)

### `GET /api/v1/config`

Returns brand, theme, contact info, SEO settings, and provider capabilities. Call this once when the frontend loads — the response is cached for 5 minutes.

The React app uses this to render the site header (logo, name), apply theme colours, and know whether the embedded checkout flow is available.

---

## Rate limits

| Endpoint group | Limit |
|---|---|
| Signing in (`/api/v1/auth/login`, patient sign-in) | 10 requests per minute per IP |
| All authenticated routes, including sign-out | 120 requests per minute per account |

If you exceed the limit you'll receive a `429 Too Many Requests` response. Retry after the `Retry-After` header value (seconds).

---

## Managing API tokens in admin

> **Coming soon** — the Filament admin will include an API Token section under Settings where you can:
> - Issue integration tokens for CRM tools, Zapier webhooks, etc.
> - View and revoke all active tokens per user
> - Set expiration dates on tokens

For now, tokens can only be issued via the `/api/v1/auth/login` endpoint.

---

## Error responses

All errors follow this shape:

```json
{ "message": "Human-readable description." }
```

Validation errors add a field-level breakdown:

```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

| HTTP Status | Meaning |
|---|---|
| `200` | Success |
| `401` | No token / expired token |
| `403` | Account inactive or insufficient permissions |
| `422` | Validation error |
| `429` | Rate limit exceeded |
| `500` | Server error (check Laravel logs) |
