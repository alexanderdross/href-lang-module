# Architecture

Detailed component and data-flow design for the cross-backend hreflang module.
Read `docs/CONCEPT.md` (v2) first for the "why", then `docs/AUTOMATION.md` for
the auto-mapping engine, and `docs/PERFORMANCE.md` / `docs/SECURITY.md` /
`docs/SEO.md` for the cross-cutting constraints.

**Subsystems at a glance.** `hrefl_client` = **Collector** (signals in) +
**Emitters** (head, sitemap, selector, JSON-LD, HTTP header out). `hrefl_hub` =
**Mapping Engine** (tiered auto-mapping) → **Registry** (versioned source of
truth) → **Review & Feedback** (CSV + UI + learning) → **Distributor**
(pre-computed alternates out) + **Monitor** (coverage, drift, validation).

## 1. Deployment topology

```
pro.boehringer-ingelheim.com
├── /            Global backend   ── hrefl_client + hrefl_hub   (hub lives here)
├── /de/         Germany backend  ── hrefl_client
├── /us/         US backend       ── hrefl_client
└── /ca/ /ca/fr/ Canada backend   ── hrefl_client   (core multilingual on)
```

One project ships two installable submodules:

- **`hrefl_client`** - on every backend.
- **`hrefl_hub`** - on the Global backend only.

Global therefore runs both: it is a normal market client *and* the hub. The two
submodules communicate over the same public hub API that the remote clients use
(no in-process shortcut), so Global is not a special case and the contract stays
honest.

## 2. Components

### 2.1 `hrefl_client` (every backend)

Responsibilities:

1. **Inventory publisher** (cron). Collects this site's indexable, canonical
   URLs and, for each, a normalized record: absolute URL, market, language,
   `hreflang` code, content signals (title, meta description, H1/breadcrumbs,
   optional body excerpt - scope is configurable for data governance), Drupal
   entity type/id, and `changed` timestamp. Within-site translations (Canada
   en/fr) are published as an already-linked set so the hub keeps them together.
   Pushes the batch to the hub ingest endpoint.
2. **Alternates consumer** (cron). Pulls this backend's resolved alternates from
   the hub serve endpoint and stores them locally (custom table + cache). This
   local store is what rendering reads, so **page delivery never depends on the
   hub being up**.
3. **Head injector** (page render). For the current page's group, injects
   `<link rel="alternate" hreflang="…">` for self + every confirmed sibling +
   one `x-default`. Merges with Drupal's native within-site translation links so
   the emitted set is single-sourced and complete (no duplicates, no conflicts).
   Implemented via a page-attachment / `hook_page_attachments()`-style hook or a
   `metatag` integration.
4. **Sitemap generator** (see §4). Builds this backend's **own** multilingual
   XML sitemap from the same resolved alternates - each `<url>` carries
   `xhtml:link` cross-site variants plus `<lastmod>` and `<priority>`.
5. **HTTP header option** (optional). For non-HTML resources (e.g. PDFs), emit
   the `Link: …; rel="alternate"; hreflang="…"` header instead of markup.
6. **Country/language selector feed** (see §4a). Exposes the current page's
   resolved alternates to a frontend dropdown component so a visitor can switch
   market/language and land on the *equivalent* page, not the market homepage.

### 2.2 `hrefl_hub` (Global backend only)

Responsibilities:

1. **Translation-group registry** - the source of truth. A *group* is a set of
   URLs across markets that are the same content; each group has a stable
   `group_uuid`; each membership row carries market, language, hreflang code,
   absolute URL, status (`proposed` | `confirmed` | `rejected`), `matched_by`
   (`ai` | `pattern` | `manual`), confidence, and provenance. Data model in
   `docs/DATA-MODEL.md`.
2. **Ingest endpoint** - receives inventory batches from every client;
   upserts URL records; flags new/changed/removed URLs for (re)matching;
   validates that URLs are reachable/200/canonical before they can become live
   alternates.
3. **AI matcher** (see §3) - proposes candidate groups for unmatched or changed
   URLs. Output is always `proposed`, never live.
4. **CSV review loop** (see §5) - export current mapping (proposed + confirmed)
   to CSV, accept an edited CSV back, diff and apply editor decisions.
5. **Serve endpoint** - for a given backend, returns the resolved, reciprocal,
   `confirmed`-only alternate set per URL (absolute URLs, valid codes,
   x-default resolved). Cacheable; clients pull on cron.
6. **Admin UI** - review queue, group browser, match confidence, validation
   warnings (missing return target, 404 target, duplicate hreflang code in a
   group, missing x-default).

## 3. Mapping Engine (tiered auto-mapping) - see AUTOMATION.md

The Mapping Engine is the core and is documented in full in
`docs/AUTOMATION.md`. In brief, it is a **tiered pipeline**, not a single LLM
call:

- **Tier A - deterministic** (shared content/PIM/asset IDs, schema.org
  `@id`/`sameAs`/`translationOfWork`, existing hreflang, learned URL/slug
  glossary): exact, cheap, explainable → confidence 1.0.
- **Tier B - semantic**: multilingual **embeddings** of title+meta+headings
  (+opt. body) with ANN vector search across markets → candidate equivalents.
  Embeddings cached per content version. Preferably a **self-hosted** model.
- **Tier C - LLM adjudication + translation**: the pluggable `AiMatcherInterface`
  with **`Copilot` and `Anthropic` providers - either one, selectable in config,
  both fully supported**, invoked **only on ambiguous candidates**. Two
  inference jobs: **adjudication** - the model **chooses among supplied candidate
  URLs or "none"**, never inventing URLs (low-temperature, schema-validated); and
  **translation** of a page's title and URL/slug into a target language to help
  locate/propose the equivalent page. All AI output is a *proposal* pending human
  review.

Score fusion yields a **confidence tier** → auto-confirm (high) / review queue
(medium) / held (low), configurable per section. Every human decision feeds the
**feedback loop** (glossary growth, threshold tuning, few-shot examples), so
manual effort shrinks over time. Matching is **event-driven + reconciled** and
runs **async in queue workers**, never on the request path
(`docs/PERFORMANCE.md`). Data minimization, approved endpoints, and
prompt-injection constraints are covered in `docs/SECURITY.md`.

### Monitor

A hub subsystem that continuously validates the graph (reciprocity as
belt-and-braces, 200/canonical/index checks), detects drift and one-sided or
dead alternates, tracks coverage %, auto-confirm precision, and time-to-map, and
integrates Google Search Console signals. Surfaces alerts and a dashboard
(off the request path).

## 4. Sitemap generation (own multilingual sitemap)

The module **generates its own multilingual XML sitemap** in `hrefl_client`
rather than delegating to `simple_sitemap`. In this setup `simple_sitemap` only
serves a **single language**, so it cannot express the cross-backend,
multi-language alternates this project needs; building our own gives full
control of the XML. (Modern `simple_sitemap` 4.x does support *within-site*
hreflang, but not the cross-backend graph, which is why we emit our own.)

- `hrefl_client` renders a Google-conformant `<urlset>` (namespace
  `xmlns:xhtml="http://www.w3.org/1999/xhtml"`) where each `<url>` lists the
  cross-backend alternates from the hub-resolved local store as `<xhtml:link
  rel="alternate" hreflang="…">` entries - so a `/us/about-us` `<url>` also
  lists the `/de/ueber-uns`, `/ca/about-us`, `/ca/fr/à-propos` and Global
  variants plus a self entry and `x-default`.
- Each `<url>` also carries the **XML enhancements `<lastmod>` and
  `<priority>`** (the hub also assists with domain-input processing and these
  enhancements).
- The alternates come from the *same* local store used for head injection, so
  head tags and sitemap can never disagree. Generation is off-request (cron),
  incremental, and chunked to the 50,000-URL / 50 MB limits (a sitemap index
  beyond that), then gzipped (`docs/PERFORMANCE.md`). `metatag` may still be
  reused for head-tag emission.
- Result: each backend publishes a multilingual sitemap whose alternates match
  its on-page annotations, both reciprocal and absolute.

## 4a. Country / language selector (frontend dropdown)

The module also **feeds a frontend dropdown** that lets a visitor switch
country and/or language - the "global country-selector" from the ticket.

- Provided as a Drupal **block/widget** (`hrefl_client`) that reads the current
  page's alternates from the same local store used for head injection, so the
  selector is always in sync with the emitted `hreflang` set.
- **Context-preserving:** each option links to the *equivalent* page in the
  target market (`/us/about-us` → `/de/ueber-uns`), not to that market's home
  page. Where no equivalent exists for a page, the option falls back to the
  target market's section/home (configurable) and is visibly marked, rather
  than 404-ing.
- **Two-axis:** because each alternate carries both market and language, the
  dropdown can present country and language either as one combined list or as
  two dependent selectors (e.g. choose Canada → offer en-CA / fr-CA).
- **Headless option:** the same per-page alternates are available as a small
  JSON payload (`drupalSettings` or a lightweight endpoint) so a decoupled
  front end can render its own selector from the module's data.
- The selector consumes the **same confirmed, validated** data as the SEO
  output - one source of truth, so what a user can switch to is exactly what
  search engines are told about.

## 5. CSV review loop (human-in-the-loop)

```
 AI + pattern proposals ─▶ hub registry (status: proposed)
                                │
              editor clicks "Export mapping CSV"
                                ▼
        CSV (one row per member, grouped by group_uuid)
                                │
        editor reviews: confirm / reject / edit / regroup
                                ▼
              editor uploads the edited CSV
                                │
        hub diffs & applies → memberships become confirmed/rejected
                                ▼
        serve endpoint now exposes the confirmed alternates
                                ▼
        clients pull on cron → head + sitemap updated
```

CSV column schema and round-trip rules are in `docs/DATA-MODEL.md` §CSV. The CSV
is an *interchange* format layered on the registry - not the live database -
preserving editorial control and a full audit trail without the staleness of a
hand-maintained source-of-truth file.

## 6. Data-flow summary (steady state, per cron cycle)

1. Each `hrefl_client` publishes its inventory to the hub.
2. Hub validates URLs, detects new/changed ones, runs the AI matcher on the
   deltas → `proposed` groups.
3. Editor exports CSV, reviews, re-uploads → `confirmed` groups.
4. Each `hrefl_client` pulls its resolved, confirmed alternates → local store.
5. On render and on sitemap generation, alternates are emitted from that local
   store - reciprocal, absolute, self-referencing, valid codes, one x-default -
   and the same store feeds the country/language dropdown.

## 7. Resilience & safeguards

- **Hub down:** clients serve the last cached alternates; rendering unaffected.
- **Broken target:** ingest + periodic revalidation drop any alternate whose
  target is not 200/canonical; never link a 404/redirect/noindex URL.
- **No confirmed mapping:** emit only the safe subset (self + native within-site
  translations); never publish an unconfirmed AI guess.
- **Reciprocity drift:** impossible by construction - one shared group per set.
- **Code collision:** validation rejects a group with two members sharing the
  same hreflang code, or with a missing/duplicate x-default.

## 8. Security (summary - full model in SECURITY.md)

- Authenticated, least-privilege, rate-limited hub endpoints; serve is read-only.
- **URL-ownership enforcement:** a backend may only assert URLs under its own
  market prefix on the approved host (blocks mapping poisoning).
- **SSRF-safe** validation crawling (family-domain allowlist, no internal IPs).
- Signed payloads/provenance; secrets via the Drupal `key` module / vault.
- AI: metadata-only default, approved region-resident endpoints, retention off,
  DLP pre-flight, prompt-injection-constrained. Prefer self-hosted embeddings.
- CSV imports sanitized against formula injection, validated, permission-gated,
  audited. Full RBAC + audit trail. See `docs/SECURITY.md`.

## 8a. Engineering standards

- Drupal coding standards; typed services, plugin types, dependency injection;
  config **schema** for all settings.
- **Mapping is content** (versioned, auditable in the DB), **settings are
  config** (exportable). Never store the live mapping in config.
- Automated tests: Unit + Kernel + Functional (matching tiers, emitters,
  validation, CSV round-trip); CI gating; semantic versioning.
- Idempotent, retrying, backed-off sync; queue-based async; incremental
  everywhere (`since` cursors, per-version embedding cache).
- Observability: structured logs (no secrets/PII), metrics (match/auto-confirm
  rate, coverage %, broken-link count, time-to-map), dashboards + alerts.

## 9. Platform-neutral contract (and the standalone WordPress version)

The hub API (ingest + serve + CSV) is defined in transport-neutral terms (JSON
over HTTPS, see `docs/DATA-MODEL.md`). The WordPress version has since been
delivered as a **standalone** all-WordPress plugin (`../../wordpress/hrefl-wp/`,
client + hub by role) that runs its **own** hub rather than joining the Drupal
one - the two are independent (an all-WP family uses one, an all-Drupal family
the other). The neutral contract still means a future client could reuse these
endpoints if cross-platform interop were ever wanted.
