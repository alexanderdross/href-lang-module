# Roadmap - Phased Delivery

Each phase is independently shippable and leaves the family in a correct (if
smaller-scope) state. Never ship a phase that can emit an incorrect live link.

## Phase 0 - POC / minimal viable mapping
**Goal:** prove cross-backend head injection end-to-end with zero AI.
- `hrefl_client` on two backends (e.g. Global + DE).
- Hand-authored mapping (a seed CSV or config) → local store → head injection.
- Enforce the core rules (absolute, self, reciprocal, x-default) from day one.
- **Exit:** view-source on `/de/ueber-uns` and `/about-us` shows a correct,
  reciprocal pair. This is the "HREFLANG POC" referenced in the ticket.

## Phase 1 - Hub + client contract (no AI)
**Goal:** the central registry and the publish/pull loop.
- `hrefl_hub` on Global: registry, ingest, serve, admin review UI.
- `hrefl_client` inventory publish + alternates pull on cron, on all backends.
- CSV export/review/import loop (manual + URL-pattern proposals only).
- Validation: 200/canonical/index checks, reciprocity, code uniqueness.
- **Exit:** editors manage all four backends' cross-links from Global via CSV;
  links appear on next sync; nothing unconfirmed goes live.

## Phase 2 - Automated multi-signal mapping engine (the core)
**Goal:** most matches automatic and explainable; humans confirm only the
ambiguous middle band; the system learns.
- **Tier A** deterministic matching (shared IDs, schema.org, existing hreflang,
  URL/slug glossary) → instant exact groups.
- **Tier B** cross-lingual **embeddings** + ANN candidate search (self-hosted
  model preferred); per-version embedding cache.
- **Tier C** LLM adjudication + title/URL translation via the `AiMatcherInterface`
  - **`Copilot` or `Anthropic`, selectable; both fully supported** - on ambiguous
  candidates only; metadata-only default; approved region-resident endpoints;
  prompt-injection-constrained.
- **Confidence tiers + auto-confirm policy** (per section); event-driven queue
  triggers + scheduled reconciliation.
- **Feedback loop:** confirmations grow the glossary and tune thresholds.
- Bulk bootstrap CSV for the first mass review.
- **Exit:** new/changed pages auto-map within minutes; auto-confirm precision
  tracked; manual review volume trending down; governance sign-off recorded.

## Phase 3 - Multilingual sitemap + hardening
**Goal:** sitemap parity and operational robustness.
- Ship the module's **own multilingual sitemap generator** (`hrefl_client`) so
  cross-site alternates appear as `xhtml:link` entries with `<lastmod>` and
  `<priority>`; head + sitemap emit the identical set. (`simple_sitemap` is
  single-language in this setup and not used for the cross-backend graph.)
- x-default management UI; per-market code configuration finalized with SEO.
- Monitoring/alerts: broken-target detection, missing-return-tag warnings,
  drift dashboard; optional HTTP `Link` header for PDFs.
- **Country/language dropdown** component: Drupal block reading the local
  alternates store, context-preserving switching, one-list or two-axis
  (country → language) modes, plus a headless JSON feed for decoupled front
  ends. Fallback handling where a page has no equivalent in the target market.
- **Exit:** each backend publishes a multilingual sitemap matching its on-page
  annotations; the selector lets visitors switch market to the equivalent page;
  ops can see and fix issues proactively.

## Phase 4 - Scale & future platforms
**Goal:** more markets, and the "maybe later WordPress" path.
- Onboarding flow for new market backends (register → publish → match → review).
- WordPress `hrefl_client` implementing the same platform-neutral hub API.
- Broader OPU/market rollout beyond Corporate.

## Phase 5 (future / optional) - Productization: freemium + premium
**Goal:** only if BI decides to productize externally. See `docs/MONETIZATION.md`.
- Open-core split: free self-hosted client + Tier-A + manual mapping; premium =
  hosted hub, Tier-B/C AI auto-mapping (metered), review UX, Monitor, multi-domain.
- **Stripe** (Checkout + Billing + metered usage) → vendor **Licensing Service**
  → signed license key validated by the hub (async, cached, offline grace).
- **Hard rule:** a lapsed licence never removes live hreflang/sitemap/selector -
  only premium *new* processing pauses. Degrade the tier, never the site's SEO.
- Respect Drupal/WordPress **GPL**: sell the hosted service + updates + support,
  not the code.

## Sequencing notes
- Phases 0→1 deliver real SEO value with no AI; AI (Phase 2) is an efficiency
  multiplier, not a prerequisite.
- The own sitemap generator (Phase 3) can begin in parallel with Phase 2
  since it reads the same local store.
- Data-governance approval for the AI providers should be started during Phase 1
  so it does not block Phase 2.
