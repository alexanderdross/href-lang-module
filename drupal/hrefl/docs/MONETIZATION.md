# Monetization - Freemium / Premium (FUTURE, later stage)

> **Status: future / exploratory.** Not part of the initial internal BI build.
> This captures how the module *could* be productized later for the wider
> Drupal/WordPress market, so today's architecture doesn't accidentally block it.
> Nothing here should be implemented until there's a decision to productize.

## 1. Why this fits the architecture

The v2 design already has a clean commercial seam: the **client is cheap and
local**, the **hub + AI tiers are the valuable, operable service**. That maps
almost 1:1 onto a freemium split - give away the client and the deterministic
core; charge for the hosted hub, the AI auto-mapping, and the operational
surface (review UX, Monitor, multi-domain). No re-architecture needed; the
premium boundary is where the money and the ongoing cost already are.

## 2. The Drupal/WordPress licensing reality (read first)

- **Drupal & WordPress core are GPL**, and any module/plugin that derives from
  them inherits **GPLv2+**. You **cannot legally stop someone redistributing the
  PHP code**, and drupal.org / wordpress.org will not host paid-gated code.
- **So you don't sell the code - you sell a *service* and *convenience*:**
  1. **Hosted hub / SaaS** (the Mapping Engine as a service) - the strongest
     model. The license key gates access to *your* hosted API, not the GPL code.
  2. **Metered AI auto-mapping** - embeddings + LLM adjudication as a paid,
     quota'd service (real per-use cost → naturally metered).
  3. **Premium add-on modules** distributed off-drupal.org (vendor site /
     license-gated Composer repo) - allowed, though the code stays GPL once
     shipped; value is in updates, support, and the connected service.
  4. **Support / SLA / done-for-you onboarding.**
- **Net:** the license key + Stripe subscription gates the **hosted service and
  updates**, which is legitimate and common (the "open-core + SaaS" pattern).
  Treat the self-hostable code as free; charge for running it for them and for
  the AI.

## 3. Tier model (illustrative)

| Capability | Free / Community | Pro (subscription) | Enterprise |
|---|---|---|---|
| Self-hosted client + hub | ✅ | ✅ | ✅ |
| Tier A deterministic matching | ✅ | ✅ | ✅ |
| Manual CSV mapping + review | ✅ | ✅ | ✅ |
| Emitters: head + sitemap + selector | ✅ | ✅ | ✅ |
| **Tier B embeddings auto-mapping** | ✖ (or bring-your-own model) | ✅ hosted/metered | ✅ self-host or hosted |
| **Tier C LLM adjudication + translation (Copilot / Anthropic)** | ✖ (BYO key, limited) | ✅ metered | ✅ + on-prem endpoints |
| In-app review queue (previews, bulk) | basic | ✅ full | ✅ full |
| Monitor dashboard + GSC + alerts | ✖ | ✅ | ✅ |
| Multi-domain / many markets | limited (e.g. ≤2) | ✅ | ✅ unlimited |
| JSON-LD, HTTP-header emitters | ✅ | ✅ | ✅ |
| SSO / RBAC / audit export | ✖ | basic | ✅ |
| Data residency, on-prem AI, GxP/compliance | ✖ | ✖ | ✅ |
| Support | community | standard | SLA + dedicated |

Gate on **value + real cost** (AI usage, hosting, market/domain count, seats),
never on hreflang correctness - correct SEO must always be achievable on free,
or the product is user-hostile and pointless.

## 4. Stripe + license-key mechanics

```
 Customer ──▶ Stripe Checkout / Billing (subscription, optional metered usage)
                     │  webhook (created/updated/canceled, usage)
                     ▼
              Licensing Service (vendor-hosted, SEPARATE from customer hub)
                     │  issues/rotates/revokes a SIGNED license key (JWT, asym.)
                     ▼
              Customer's hrefl_hub  ──validates key──▶ enables tier features
                     │  caches entitlement · async refresh · offline grace
                     ▼
              Feature flags + AI quota enforcement
```

- **Stripe:** Checkout for signup, Billing for subscriptions, **metered/usage-
  based** items for AI matching volume, Customer Portal for self-serve, webhooks
  as the source of truth for entitlement changes.
- **License key = signed entitlement** (JWT signed with the vendor's private
  key; module verifies with the public key). Carries tier, limits (markets/
  domains/seats), AI quota, expiry.
- **Validation is performance-safe** (consistent with `docs/PERFORMANCE.md`):
  verify signature locally, refresh entitlement **async** on cron, cache it, and
  allow a **grace period** so a transient outage never breaks the site.
- **Quota enforcement at the hub** (server-side), never client-side; metered
  usage reported back to Stripe.
- **Key storage** via the Drupal `key` module / secrets manager.

## 5. The non-negotiable rule: lapsing never breaks live SEO

If a subscription lapses or the license can't be validated, the module **keeps
serving the already-published hreflang, sitemap alternates, and selector** - the
customer's live SEO is untouched. Only **premium *new* processing pauses**:
AI auto-mapping stops proposing, the hosted hub/dashboard locks, quotas apply.
Ripping out live annotations on lapse would tank a customer's rankings - never
acceptable, and also the fastest way to lose trust. Degrade to the free tier's
behaviour (deterministic + manual), don't degrade the site.

## 6. Anti-abuse & integrity (light)

- Asymmetric-signed keys (no shared secret to leak); short-lived entitlement
  cache with server refresh; revocation list checked on refresh.
- Bind entitlement to a customer/site identifier; detect obvious key sharing via
  usage telemetry (privacy-respecting, aggregate).
- Accept that GPL code can be forked - compete on the **hosted service, model
  quality, updates, and support**, not on DRM. Don't over-engineer copy
  protection.

## 7. WordPress angle

The same licensing service and hosted hub serve a future WordPress client
(platform-neutral hub API). WordPress's plugin market is more accustomed to
freemium (Freemius, EDD, WooCommerce patterns), so the Pro/hosted model
translates directly.

## 8. If/when to revisit

Only after: (a) the internal BI build is proven, (b) there's appetite to
productize externally, and (c) legal/commercial sign-off on the open-core + SaaS
model. Until then this is a design guardrail - keep the client/hub boundary and
the license-validation-is-async, never-break-SEO principles intact so the door
stays open.
