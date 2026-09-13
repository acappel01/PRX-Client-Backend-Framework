# Documentation Index

Per-module documentation: `user.md` = admin operator guide, `dev.md` = architecture / data model / API reference for developers.

| Area | What it covers | Docs |
|---|---|---|
| Architecture | Current-state system architecture, layering rules, module map | [`architecture/dev.md`](architecture/dev.md) (canonical) · [`architecture/IMPLEMENTATION_PLAN.md`](architecture/IMPLEMENTATION_PLAN.md) (historical build plan) |
| Frontend implementation | How a new company builds a frontend against `/api/v1` | [`frontend/dev.md`](frontend/dev.md) · [`frontend/user.md`](frontend/user.md) |
| API foundation | Sanctum auth, envelope, versioning, error shapes | [`api-foundation/dev.md`](api-foundation/dev.md) · [`api-foundation/user.md`](api-foundation/user.md) |
| API clients & tokens | Issuing frontend tokens, origin pinning | [`api-clients/dev.md`](api-clients/dev.md) |
| Settings | Brand / theme / contact / SEO / integration settings pattern | [`settings/dev.md`](settings/dev.md) · [`settings/user.md`](settings/user.md) |
| CMS | Pages, typed sections, media | [`cms/dev.md`](cms/dev.md) · [`cms/user.md`](cms/user.md) |
| Page builder | Section registry, flexible types, globals, menus, regions, revisions, envelope contract | [`page-builder/dev.md`](page-builder/dev.md) · [`page-builder/user.md`](page-builder/user.md) |
| Catalog | Products, packages, plans, provider mapping, catalog API | [`catalog/dev.md`](catalog/dev.md) · [`catalog/user.md`](catalog/user.md) |
| Blog | Posts, categories, tags, blog API | [`blog/dev.md`](blog/dev.md) · [`blog/user.md`](blog/user.md) |
| Knowledge base | Compound monographs, the clinical review gate, regulatory status, the compound import | [`knowledge-base/dev.md`](knowledge-base/dev.md) · [`knowledge-base/user.md`](knowledge-base/user.md) |
| Cart | Token-based cart API | [`cart/dev.md`](cart/dev.md) · [`cart/user.md`](cart/user.md) |
| Checkout | prx-embed vs local gateway flow, gateway config endpoint | [`checkout/dev.md`](checkout/dev.md) |
| Orders | Order shells, webhook sync, shipments | [`orders/dev.md`](orders/dev.md) · [`orders/user.md`](orders/user.md) |
| Leads | Lead capture, consents, UTM attribution | [`leads/dev.md`](leads/dev.md) · [`leads/user.md`](leads/user.md) |
| Workflows | Operator-built automation: triggers, conditions, actions, run log | [`workflows/dev.md`](workflows/dev.md) · [`workflows/user.md`](workflows/user.md) |
| Quiz | Intake quiz results page — the copy a visitor reads after finishing | [`quiz/user.md`](quiz/user.md) |
| Integrations | Provider catalogue, capability routing, the PHI boundary and its attestation | [`integrations/dev.md`](integrations/dev.md) · [`integrations/user.md`](integrations/user.md) |
| Payments | Gateway abstraction, merchant accounts (NMI / Authorize.net / Stripe / Square) | [`payments/dev.md`](payments/dev.md) · [`payments/user.md`](payments/user.md) |
| Intake | Intake schema API, wizard strategy | [`intake/dev.md`](intake/dev.md) · [`intake/user.md`](intake/user.md) |
| prescribe-rx | Clinical API integration, partner guide | [`prescribe-rx/dev.md`](prescribe-rx/dev.md) · [`prescribe-rx/user.md`](prescribe-rx/user.md) · [`prescribe-rx/partner-implementation-guide.md`](prescribe-rx/partner-implementation-guide.md) |
| API spec | Exported OpenAPI document (live version at `/api/docs`) | [`api/openapi.json`](api/openapi.json) |

Convention: every shipped module has both `user.md` and `dev.md`; a module isn't "done" without them. Document as you build.
- [`portal/dev.md`](portal/dev.md) — the patient-portal proxy: the allowlist, the server-side ranking, and the traps it has already hit
- [`portal/user.md`](portal/user.md) — for support: how patients create accounts and reset passwords by emailed link, and what to check when they can't
