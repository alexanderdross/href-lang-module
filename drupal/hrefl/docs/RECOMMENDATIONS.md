# Recommendations - what else to add, change or improve

Beyond the core concept, these are the additions and scope decisions worth
making. Grouped by theme, each with *why* and a rough priority
(**P1** = decide/do early, **P2** = important, **P3** = later/nice-to-have).

## A. Mapping correctness & coverage

- **Non-1:1 and partial equivalence (P1).** Real families aren't perfectly
  parallel. Support many-to-one (a global page ↔ a section elsewhere) and
  **partial/fallback mapping**: when no exact equivalent exists, map to the
  nearest parent/section and mark it, so the selector degrades gracefully and no
  hreflang points at nothing. Decide the fallback policy explicitly.
- **Editorial lock / "do-not-map" (P1).** Let editors **pin** a mapping so
  automation never overrides it, and flag pages that should never be mapped
  (legal, market-specific). Automation must respect human authority.
- **Region-vs-language modelling (P1).** Make market, language, and hreflang code
  first-class and independent (e.g. `en-US`, `en-CA`, `en-GB` share language,
  differ by region; a market may host several languages). Prevents code
  ambiguity later.
- **RTL / new-script readiness (P2).** If Arabic/Hebrew markets are ever added,
  the selector and content need `dir="rtl"` and correct `lang` handling - cheap
  to design in now.

## B. Multi-domain / true "bi-family" scope (P1 - needs a decision)

The ticket mentions the "bi-family" and a more complex **BICOM** case. Today's
setup is one hostname with path prefixes, but the family may include **separate
domains / ccTLDs / brands**. Confirm scope: if cross-**domain** hreflang is in
play, the design already uses absolute URLs and per-backend base URLs, but
**URL-ownership enforcement, the host allowlist, and onboarding** must be defined
per domain, not just per path prefix. This materially affects the security model
and should be settled at the TA discussion.

## C. Editorial workflow & governance

- **Review UX that scales (P1).** The CSV round-trip is the interchange, but the
  in-app **review queue** should show side-by-side previews/thumbnails,
  the confidence + *why* (signals), bulk confirm/reject, and keyboard-driven
  flow. This is where editor time is won or lost.
- **Approval integration (P2).** Tie confirmation into Drupal **content
  moderation** and roles (e.g. per-market approver), with an audit trail.
- **Notifications (P2).** Alert editors/SEO when pages need review, coverage
  drops, or a broken/one-sided alternate appears (email/Teams/Slack).
- **Dry-run / preview (P2).** Preview the hreflang set and selector for a page
  before publish; diff before applying a bulk import.

## D. Frontend / UX / accessibility

- **Accessible selector (P1).** WCAG: full keyboard support, ARIA labels,
  `hreflang`/`lang` on each link, visible focus, screen-reader-friendly. Bake in
  from the first component.
- **Endonyms (P2).** Label languages in their own language (Deutsch, Français),
  not English - better UX and conversion.
- **Non-redirect "available in your language" hint (P2).** Since auto-redirects
  are excluded, a **dismissible, consent-aware banner** ("This page is available
  in Deutsch") is the Google-friendly alternative - user choice, no redirect.
- **Remembered choice (P3).** Functional-only, consent-aware cookie; never geo
  profiling.

## E. Measurement & business value

- **Prove the SEO ROI (P2).** Wire **Google Search Console** (per-hreflang
  impressions/clicks, coverage, errors) and selector-usage analytics into the
  Monitor dashboard, so the module's value (protected rankings, cross-market
  navigation) is demonstrable - useful for the SEO epic.
- **KPIs as first-class (P2).** Coverage %, auto-confirm precision, time-to-map,
  broken-alternate count, review backlog - tracked and trended, not ad hoc.

## F. Platform, scale & resilience

- **Cross-domain / non-CMS client (P2/P3).** Beyond the planned WordPress client,
  an **edge client** (e.g. Cloudflare Worker) could inject hreflang for pages not
  served by a CMS, using the same hub API - future-proofing the "bi-family".
- **Sitemap index automation (P2).** Generate a sitemap index over per-language
  sitemaps, auto-submit to Search Console, keep robots.txt in sync.
- **Registry rollback / DR (P1).** The hub is the source of truth - version the
  registry (done in the data model), and define **backup / disaster recovery**
  for it. A bad bulk change must be one-click reversible.
- **AI cost & availability guardrails (P2).** Budget caps, cost dashboards, and
  graceful fallback (Tier-B only) if a provider is unavailable or over quota.
- **Contrib dependency risk (P2).** The module ships its **own** multilingual
  sitemap generator, so there is no `simple_sitemap` integration API to track for
  the cross-backend graph (removing that risk). Keep the head-tag emission path
  behind a thin `metatag` adapter so a future API change (or swap) stays
  contained.

## G. Compliance (pharma-specific)

- **Regulated-content handling (P1).** Medical/regulated pages may have
  market-specific legal variants that must **not** be presented as equivalents.
  Add a content-classification gate so such pages are review-only or excluded,
  and keep the AI data-governance controls in `docs/SECURITY.md` strict
  (metadata-only, region-resident, retention off, DLP).

## Suggested near-term decisions (for the TA discussion)

1. Cross-**domain** in scope, or path-prefix only? (drives security + onboarding)
2. Fallback/partial-mapping policy when no exact equivalent exists.
3. Auto-confirm on at launch, or review-everything first then relax?
4. Per-market hreflang code table + x-default owner (finalize with SEO).
5. Approved AI endpoints + embedding model (self-hosted?) for BI governance.
6. Regulated-content classification rule.
