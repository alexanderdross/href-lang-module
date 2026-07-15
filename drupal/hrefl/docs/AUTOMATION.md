# Automation & URL Auto-Mapping Engine (the core)

This is the heart of the module. The goal: **automatically discover, and keep
correct, the mapping between a URL and its localized equivalents across the
independent backends** - with human effort reduced to the genuinely ambiguous
cases and shrinking over time.

## 1. Design principles

- **Cheap-and-certain before expensive-and-fuzzy.** Run deterministic signals
  first; only escalate to embeddings, then to an LLM, for what remains.
- **Explainable.** Every mapping records *why* it was made (which signals,
  which scores) so editors can trust and audit it.
- **Least data exposure.** Deterministic and embedding tiers need only
  metadata/derived vectors; the LLM sees the minimum, through approved
  endpoints. See `docs/SECURITY.md`.
- **Event-driven + reconciled.** React to content changes immediately; a
  scheduled full pass catches anything missed.
- **Self-improving.** Every human confirm/reject feeds the glossary, thresholds,
  and prompt examples, so the manual tail shrinks.
- **Safe by default.** Nothing uncertain goes live; unmatched pages degrade to
  the safe subset (self + native within-site translations).

## 2. Signals the Collector extracts (per URL)

Gathered on each backend and sent to the hub (scope is governance-gated):

- **Identity signals (strongest):** an optional shared **Global Content ID**
  field, PIM/DAM/product IDs, campaign IDs, DOIs/GTINs, media asset checksums.
- **Structured signals:** schema.org `@id`, `sameAs`, `inLanguage`,
  `translationOfWork`/`workTranslation`, breadcrumbs, canonical URL, content
  type/bundle, taxonomy/tags.
- **URL signals:** path, slug tokens, section, existing path-alias patterns.
- **Existing hreflang:** any annotations already present (bootstrap/verify).
- **Content signals (for embeddings/LLM):** title, meta description, H1–H3,
  first paragraph, key entities. Full body is opt-in only.
- **Freshness:** `changed`, publication state, moderation state, `lastmod`.

## 3. The matching pipeline (tiered)

```
 changed/new URL ─▶ ┌────────────────────────────────────────────┐
                    │ TIER A - Deterministic (exact, cheap)       │
                    │  • shared Global Content ID / PIM / asset   │
                    │  • schema.org @id / sameAs / translationOf  │
                    │  • existing hreflang already declared       │
                    │  • URL/slug glossary (about-us↔ueber-uns…)  │
                    │  hit → confidence 1.0, matched_by=key       │
                    └───────────────┬────────────────────────────┘
                        no exact key │
                                     ▼
                    ┌────────────────────────────────────────────┐
                    │ TIER B - Semantic candidates (embeddings)   │
                    │  • multilingual sentence embeddings of      │
                    │    title+meta+headings (+opt body)          │
                    │  • ANN vector search across other markets   │
                    │  • cosine ≥ τ_high → strong candidate       │
                    │  • narrows to top-k per target market       │
                    └───────────────┬────────────────────────────┘
                     ambiguous / tie │ (multiple close candidates,
                                     │  mid-band similarity)
                                     ▼
                    ┌────────────────────────────────────────────┐
                    │ TIER C - LLM adjudicate + translate (Copilot|Anthropic)│
                    │  • adjudicate: given source + top-k         │
                    │    candidates, pick equivalent (or none)    │
                    │  • translate: title/URL slug → target lang  │
                    │  • never invents URLs; chooses from input   │
                    └───────────────┬────────────────────────────┘
                                     ▼
                    ┌────────────────────────────────────────────┐
                    │ Score fusion → confidence tier → routing    │
                    └────────────────────────────────────────────┘
```

**Why this order.** Tier A resolves the easy, high-value cases for free and with
certainty. Tier B (embeddings) is the workhorse for cross-language equivalence -
it finds `à-propos` ≈ `about-us` without a glossary and without an LLM per pair.
Tier C spends the LLM (and any external data exposure) *only* on the genuinely
ambiguous few, where it adds the most value. Embeddings are cached per content
version, so re-matching a changed page is incremental.

## 4. Confidence tiers → routing (configurable, per section)

| Tier | Trigger | Default action |
|------|---------|----------------|
| **High** | Tier-A key match, or embeddings+LLM agree ≥ `τ_confirm` | **Auto-confirm** → live. High-risk sections (e.g. regulated/medical) can be set to "review even if high". |
| **Medium** | Single plausible candidate below confirm threshold, or LLM medium confidence | **Review queue** (CSV + UI); not live until confirmed. |
| **Low** | No candidate above `τ_floor`, or conflicting candidates | **Held**; surfaced as "needs attention / no confident match". |

Thresholds `τ_high`, `τ_confirm`, `τ_floor` are configuration and can differ per
market/section. Auto-confirm can be globally disabled (all matches reviewed)
for a cautious launch, then relaxed as trust builds.

## 5. Triggers - when matching runs

- **Event-driven (primary):** Drupal `hook_entity_insert/update/delete`,
  content-moderation transitions, and path-alias changes enqueue the affected
  URL to a **Queue** on its backend; the backend notifies the hub (webhook or
  next micro-cron). Near-real-time, no editorial blocking.
- **Sitemap delta:** changed `lastmod` entries trigger re-check of those URLs.
- **Scheduled reconciliation:** a full pass (nightly/weekly) re-validates the
  whole family, catches missed events, re-embeds stale content, and repairs
  drift.
- **On-demand:** editors can trigger "re-match this page / this section".

All matching is **asynchronous** (queue workers), never on the request path.

## 6. Change detection, drift & lifecycle

- **New page** → matched on publish; joins or forms a group.
- **Edited page** (title/slug/body change) → re-embedded, re-matched; group
  membership updated; reciprocal links refreshed automatically.
- **Moved/renamed URL** → old URL retired, new URL carried into the group;
  no dangling alternates.
- **Unpublished/deleted/redirected** → membership retired; **all reciprocal
  members updated** so nobody links to a dead URL.
- **Orphan/one-sided detection** → Monitor flags any member whose target is
  missing, non-200, non-canonical, or non-reciprocal (though reciprocity is
  structural, validation is belt-and-braces).

## 7. The feedback loop (why manual effort shrinks)

Every human decision in review is captured and reused:

- **Confirm/reject pairs** become labelled examples: raise/lower fused-score
  weighting, and seed few-shot examples for Tier-C prompts.
- **Slug corrections** grow the **URL/slug glossary** (a learned dictionary of
  equivalent path tokens per language) that Tier A uses next time - so a class
  of matches that needed review once becomes deterministic thereafter.
- **Threshold auto-tuning:** track precision/recall of auto-confirmed vs
  corrected matches; recommend threshold adjustments.
- **Regroup actions** teach the engine about content families it clustered
  wrongly.

The result is a system that starts conservative (more review) and, as it learns
the site's patterns, safely auto-confirms more - the opposite of a static CSV
that rots.

## 8. Bulk bootstrap (first run)

1. Crawl every backend's XML sitemap → inventory.
2. Tier A over all identity/structured/existing-hreflang signals → instant exact
   groups.
3. Embed the remainder → cluster across markets → candidate groups.
4. Tier C adjudication on ambiguous clusters.
5. Export one **bulk review CSV**; editors confirm/correct en masse.
6. Publish confirmed groups; the steady-state event loop takes over.

## 9. AI provider abstraction (Tier C)

Pluggable `AiMatcherInterface`; **either Microsoft Copilot or Anthropic**,
selectable in config - **both are fully supported** for all inference and
neither is mandated. Providers only adapt transport/auth and expose two
inference jobs on a common schema:

- **Adjudication** - source record plus candidate list → chosen index +
  confidence + rationale. The model **chooses among supplied candidates and may
  choose "none"** - it never fabricates URLs.
- **Translation** - translate a page's **title and URL/slug** into a target
  language (`{title, slug}`), used to locate/propose the equivalent page across
  the language sites and to give reviewers a localized title/slug to check. The
  slug is sanitized to a safe URL token; the output is always a *proposal* that
  a human reviews before it can go live.

Deterministic, low-temperature. Endpoints must be the org-approved,
region-resident ones; data scope is metadata by default (`docs/SECURITY.md`).
AI calls are deliberate (on-demand / for held pages), not on every cron, to keep
cost and governance controlled. The embedding model is likewise pluggable
(self-hosted multilingual model preferred to keep content in-house).

## 10. What "good automation" means here (KPIs)

- **Coverage:** % of eligible pages with a confirmed cross-market group.
- **Auto-confirm rate** and its **precision** (share later corrected).
- **Time-to-map** a newly published page (event → live alternate).
- **Manual review volume** trending down over time.
- **Broken/one-sided alternates:** target zero (Monitor-enforced).

These are surfaced on the hub dashboard (`docs/PERFORMANCE.md` covers how this
stays off the request path).
