# hrefl - Cross-Backend hreflang for Drupal

Emits correct, reciprocal `hreflang` annotations across several independent,
path-prefixed Drupal backends that together form one international site, and
feeds those alternates into the module's **own** multilingual XML sitemap and a
crawlable country/language selector.

This is the Drupal implementation. A **standalone WordPress version** lives in
`../wordpress/hrefl-wp/` (all-WP family, its own hub — not interoperable with
this Drupal hub). Concept + design in the canonical doc set under
[`docs/`](docs/) (`docs/CONCEPT.md`, `docs/AUTOMATION.md`, `docs/ARCHITECTURE.md`,
plus `docs/SETUP-GUIDE.md`, `docs/QA-ASSESSMENT.md`, `docs/UAT-PLAN.md`,
`docs/SECURITY-TEST-PLAN.md`, `docs/PERFORMANCE-TEST-PLAN.md`).

## Packages

- **hrefl** (this umbrella): no code of its own; enable the submodules below.
- **hrefl_client**: install on *every* backend. Collects page inventory and
  signals, pulls resolved alternates from the hub on cron, stores them locally,
  and emits `hreflang` head tags, sitemap alternates, and the selector. No
  cross-backend call ever happens at request time.
- **hrefl_hub**: install on the *Global* backend only. Holds the versioned
  translation-group registry (source of truth), runs the tiered auto-mapping
  engine (deterministic, embeddings, LLM adjudication + title/URL translation
  via Copilot or Anthropic - both fully supported and selectable), the CSV
  review loop, and the ingest/serve HTTP API.

## Install

```
composer require boehringer-ingelheim/hrefl
drush en hrefl_client            # on every backend
drush en hrefl_hub               # on the Global backend only
```

Then configure the hub URL and credentials at `/admin/config/search/hrefl`
(client). On the hub, `/admin/config/search/hrefl-hub` lets the admin pick the
**AI matcher provider - Microsoft Copilot or Anthropic (both fully supported)** -
and enter each one's endpoint, model and API key. The API key is referenced by
name from the [`key`](https://www.drupal.org/project/key) module (never stored in
config); for local development set `HREFL_COPILOT_KEY` / `HREFL_ANTHROPIC_KEY`
instead. Only the selected provider is called at run time; the other stays
configured as a ready alternative.

## Phase 0 quickstart (POC, no hub, no AI)

Prove cross-backend head injection end-to-end from a hand-authored mapping,
per `docs/ROADMAP.md` Phase 0:

```
drush en hrefl_client                                   # on Global and DE
drush hrefl:seed modules/custom/hrefl/modules/hrefl_client/seed/example.seed.json
drush hrefl:show https://pro.boehringer-ingelheim.com/de/ueber-uns
```

`hrefl:seed` loads a serve-shaped JSON mapping straight into the local store
(bypassing the hub); `hrefl:show` prints the exact `<link rel="alternate">`
tags the page will emit. Seed the same file on both backends and view-source on
`/about-us` and `/de/ueber-uns` shows the correct, reciprocal pair. Every set is
run through `HreflangValidator` first, so codes are normalized, URLs are
absolute, and there is exactly one `x-default`.

## Status

Work in progress, tracking `docs/ROADMAP.md`:

- **Phase 0 (POC)** - done. Seed → local store → validated head injection; the
  `hrefl:seed` / `hrefl:show` Drush commands prove a reciprocal pair without
  the hub.
- **Phase 1 (hub + client contract, no AI)** - in place. Registry, ingest/serve
  API, CSV round-trip, and the in-app **review queue**
  (`/admin/config/search/hrefl-hub/review`). On cron the hub runs **URL-pattern
  matching** (Tier A): ingested pages coalesce into *proposed* cross-market
  groups by shared leaf-slug, bridged across languages by a **learned glossary**
  that grows from every confirmation. Confirmations pass a shared guard: a
  member goes live only if its target is validated (SSRF-safe 200 / canonical /
  indexable check, run off-cron in a queue) and the resulting group stays
  structurally valid (unique codes, ≤1 x-default). Remaining polish: richer
  review UX (side-by-side previews, bulk actions) and Tier-A identity signals
  (schema.org `@id`, existing hreflang) in the collector.
- **Phase 2 (AI mapping engine)** - in place. `MappingEngine` runs the full
  tiered flow: **Tier A** deterministic (leaf-slug + learned glossary) →
  **Tier B** cross-lingual embeddings (`EmbeddingMatcher` + `VectorStore`, ANN
  candidate search via the `HreflEmbedding` plugin type) → **Tier C** LLM
  adjudication over those candidates (`HreflAiMatcher` plugins - `Copilot` and
  `Anthropic`, both fully supported and selectable), plus title/slug translation
  (`TranslationProposer`). Confidence tiers drive auto-confirm / review / hold.
- **Phase 3 (sitemap + hardening)** - in place. The module's **own multilingual
  sitemap** (`/hrefl-sitemap.xml`: `xhtml:link` alternates + `lastmod` +
  `priority`, chunked into an index beyond 50k URLs), HTTP `Link` header for
  non-HTML, the country/language selector, and the Monitor dashboard.

What is *not* code but **environment wiring**: the AI provider endpoint + API
key (referenced by name via the `key` module) and the embedding endpoint. Until
those are configured the engine simply runs Tier A only - which is a complete,
shippable configuration on its own. AI is an efficiency multiplier, never a
prerequisite.

## Key design rules (enforced here)

- Absolute, reciprocal, self-referencing hreflang; one `x-default` per group.
- Zero cross-backend calls at request time (read from the local cached store).
- No IP/geo auto-redirects; the selector is user-initiated and crawlable.
- AI sees metadata only by default; the LLM chooses among supplied candidates
  or "none" and never invents URLs.

## Coding standards

Drupal coding standards, typed properties, constructor DI. The module ships a
`phpcs.xml.dist` (Drupal + DrupalPractice, with documentation-density sniffs
relaxed - see the file header for the rationale). Run from the module root:

```
vendor/bin/phpcs --standard=phpcs.xml.dist .
```
