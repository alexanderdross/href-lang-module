# Concept (v2 - rethought) - Automated Cross-Backend hreflang

Status: draft for TA discussion · Origin: WFACQUIA-81 · Target: Drupal 10/11

This revision keeps the v1 topology and hub idea but re-centres the whole design
on **automated, multi-signal URL auto-mapping** and holds every decision against
four lenses: **web performance, SEO, security, and engineering best practice**.
Deep-dives live in `docs/AUTOMATION.md`, `docs/PERFORMANCE.md`,
`docs/SECURITY.md`, `docs/SEO.md`.

## 1. Problem (unchanged)

One hostname (`pro.boehringer-ingelheim.com`), several **independent** Drupal
backends mounted on path prefixes (`/`, `/de/`, `/us/`, `/ca/` + `/ca/fr/`).
`hreflang` between languages *inside* one backend (Canada en/fr) is native and
solved. The gap is **cross-backend**: four separate Drupal installs, blind to
each other, so nothing links `/us/about-us` ↔ `/de/ueber-uns` ↔ `/ca/about-us`.

## 2. What changed in the rethink

v1 treated AI matching as a single "proposer + CSV review" step. v2 makes
**automation the product**:

1. **Auto-mapping is a multi-signal engine, not one LLM call.** Deterministic
   signals (shared IDs, structured data, URL/slug glossary, existing hreflang)
   run first and cheaply; **cross-lingual embeddings** generate candidates;
   an **LLM (Copilot or Anthropic, selectable; both fully supported) adjudicates
   the ambiguous ones** and can **translate a page's title/URL** to help locate
   the equivalent. This is more accurate, far cheaper, and exposes far less
   content to external APIs. See `docs/AUTOMATION.md`.
2. **Confidence-tiered auto-confirm.** Exact/high-confidence matches
   auto-publish (per-section policy); the middle band goes to human review; the
   low band is held. Manual effort shrinks to the genuinely ambiguous cases,
   and keeps shrinking as the **feedback loop** turns every human decision into
   glossary entries and threshold tuning.
3. **Event-driven, not just cron.** Publishing/updating/retiring a page enqueues
   re-matching immediately (Drupal Queue), so the mapping tracks content in near
   real time; a scheduled full reconciliation is the safety net.
4. **Performance is a hard constraint, not an afterthought.** Zero cross-backend
   calls on page render; everything is pre-resolved and cache-tag invalidated;
   the sitemap method is primary at scale to avoid `<head>` bloat.
5. **No IP/geo auto-redirects - ever.** Market/language switching is
   **user-initiated** via a crawlable selector; hreflang informs search engines.
   This is both an explicit product decision and an SEO/performance best
   practice (auto-redirects harm crawlability, Core Web Vitals, and UX).
6. **Security & data governance are first-class.** URL-ownership enforcement,
   SSRF-safe crawling, signed payloads, metadata-only AI by default with
   approved regional endpoints, CSV-injection-safe imports.

## 3. Architecture in one line

Each backend runs a **Collector + Emitters** (`hrefl_client`); the Global
backend additionally hosts the **Mapping Engine + Registry + Review +
Distributor + Monitor** (`hrefl_hub`). Content signals flow up; a pre-computed, validated,
reciprocal alternate set flows down and is emitted with zero runtime coupling.

```
 CLIENT (every backend)                 HUB (Global backend)
 ┌───────────────────┐   signals   ┌─────────────────────────────────┐
 │ Collector         │────────────▶│ Mapping Engine                  │
 │  page inventory + │             │  A deterministic (IDs, schema,  │
 │  matching signals │             │    slug glossary, existing hl)  │
 │                   │             │  B embeddings → candidates      │
 │                   │             │  C LLM adjudicate + translate   │
 │                   │             │  (Copilot/Anthropic) ambiguous  │
 │                   │             │        │ confidence tiers       │
 │                   │             │        ▼                        │
 │                   │             │ Registry (translation groups,   │
 │                   │             │   versioned source of truth)    │
 │                   │             │   ├─ auto-confirm (high)         │
 │                   │             │   ├─ review queue (CSV+UI, mid)  │◀─ Editor
 │                   │             │   └─ held (low)                  │   feedback
 │ Emitters          │  resolved   │ Distributor (pre-compute per-   │   loop
 │  • <head> hreflang│◀────────────│   URL alternates)               │
 │  • own XML sitemap│  alternates │ Monitor (coverage, drift,       │
 │    (xhtml+lastmod)│             │   broken targets, GSC)          │
 │  • country/lang   │             └─────────────────────────────────┘
 │    selector (crawl│
 │    able, no redir)│   NO cross-backend calls at request time.
 │  • JSON-LD, HTTP  │   Everything pre-resolved + cache-tagged.
 └───────────────────┘
```

## 4. The four lenses (summary; full docs linked)

**Web performance (`docs/PERFORMANCE.md`).** Alternates are pre-resolved into a
local store; page render never calls the hub or siblings. Render-cached with
per-group cache tags so a mapping change invalidates only affected pages.
Sitemap is the primary hreflang carrier at scale (chunked to 50k URLs / 50MB,
gzipped, generated off-peak, incrementally) to keep `<head>` small. The selector
ships as static page data (crawlable `<a>` links, no per-request API, no layout
shift). Matching (embeddings/LLM) runs async off the request path with cached
embeddings.

**SEO (`docs/SEO.md`, `docs/HREFLANG-RULES.md`).** Reciprocal, self-referencing,
absolute, valid-code annotations - reciprocity guaranteed by the shared-group
model. Canonical/hreflang consistency; one `x-default`; only indexable/200/
canonical targets. Crawlable selector; **no auto-redirect**; optional schema.org
`inLanguage` / `sameAs` for machine-readability; GSC International Targeting
monitoring and automated validation.

**Security (`docs/SECURITY.md`).** Authenticated, least-privilege hub endpoints;
a backend may only publish URLs under **its own** market prefix (blocks mapping
poisoning); SSRF-safe validation crawling (family-domain allowlist, no internal
IPs); signed payloads; secrets in the `key` module/vault; AI defaults to
metadata-only through **approved, region-resident** endpoints with retention
off and full audit (pharma/GxP aware); CSV imports sanitized against formula
injection and permission-gated with an audit trail.

**Engineering best practice.** Drupal coding standards + config schema;
automated tests (Kernel/Functional) and CI; mapping is *content* (versioned,
auditable), settings are *config*; idempotent, retrying, backed-off sync;
observability (match rate, auto-confirm rate, coverage %, broken-link count);
platform-neutral hub API so a WordPress client can join later.

## 5. Why the multi-signal engine is the heart of it

The ticket's real pain is that **keeping the mapping correct as content changes**
is unbounded manual work. A pure-LLM matcher is expensive, opaque, exposes
content, and still needs review. The layered engine turns most matches into
cheap, explainable, deterministic decisions; uses embeddings to find the
non-obvious equivalents across languages; spends the LLM only where it adds
value; auto-confirms what's safe; and **learns from every correction** so the
manual tail shrinks over time. That is what makes cross-backend hreflang
sustainable rather than a perpetual chore. Full design: `docs/AUTOMATION.md`.

## 6. Scope

- **In scope:** the automated multi-signal mapping engine; hub registry +
  review/feedback loop; Drupal 10/11 client + hub; **Copilot or Anthropic**
  adjudication + title/URL translation (both fully supported, selectable); head +
  own multilingual sitemap + selector + JSON-LD emitters; validation and
  monitoring.
- **Out of scope (now):** content/translation authoring; visual/brand design of
  the selector beyond a working, accessible default; WordPress client (kept
  possible via the platform-neutral API); **any IP/geo auto-redirect** (excluded
  by design).

## Sources
- [Localized versions of your pages - Google Search Central](https://developers.google.com/search/docs/specialty/international/localized-versions)
- [Multilingual/multinational annotations in Sitemaps - Google (ticket reference)](https://developers.google.com/search/blog/2012/05/multilingual-and-multinational-site)
- [Multilingual SEO issues & fixes - Seobility](https://www.seobility.net/en/blog/multilingual-seo-issues/)
- [Hreflang at scale: automation - Hashmeta](https://hashmeta.com/blog/hreflang-at-scale-automation-tips-for-multi-lingual-seo-agencies/)
- [Multi-language & multi-region XML sitemap best practices - GtechMe](https://www.gtechme.com/insights/best-practices-for-multi-language-and-multi-region-xml-sitemaps-hreflang-support/)
