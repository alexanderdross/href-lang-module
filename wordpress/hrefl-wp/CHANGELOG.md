# Changelog - hrefl (WordPress)

All notable changes to the WordPress plugin. Dates are ISO (YYYY-MM-DD).

## 0.3.1 - 2026-07-22 - optional auto-confirm (opt-out of manual review)

- **Auto-confirm setting** - a new hub checkbox, **off by default**, under a
  "Human review" heading. Left off (recommended), a human confirms every match
  before it goes live - the default human-in-the-loop. Turned on, the hub
  auto-confirms matched mappings on the next cron run, but **only through the
  same correctness guard** as manual confirm (`Hrefl_Review_Actions`): a mapping
  is published only once its target has validated (HTTP 200, indexable) and no
  other confirmed member in its group already uses that hreflang code. A broken
  or colliding mapping can never go live automatically. Mirrors the Drupal
  `auto_confirm_enabled` option. New `Registry::proposed_valid_members()` and an
  `AutoConfirmTest` guarding the safe default.

## 0.3.0 - 2026-07-21 - feature parity with Drupal

Closes the documented condensed-port gaps - the WordPress plugin now matches the
Drupal module feature-for-feature:

- **Human-readable selector labels** - the switcher shows "English (United
  States)" / "Deutsch" (endonyms) instead of the raw code; `hreflang`/`lang`
  stay the machine code. New `Hrefl_Locale` helper.
- **Headless selector feed** - `GET /wp-json/hrefl/v1/selector?url=...` returns
  the labelled alternates as JSON (public, reads the local store).
- **HTTP Link header** - hreflang alternates are emitted as `Link:` headers on
  non-HTML responses (feeds, attachments), where a `<head>` tag is not an option.
- **SSRF DNS pinning** - the validation fetch is pinned to the vetted public IP
  (via the `http_api_curl` hook), closing the DNS-rebinding window; falls back to
  the host allowlist when the curl transport is not used.
- **AI matching engine (Tier B + C)** - the full Drupal engine, ported:
  - **Tier B embeddings** (`Hrefl_Embedding_Matcher` + `Hrefl_Vector_Store`):
    embeds title+slug against a configurable endpoint (self-hosted preferred),
    stores one vector per URL, and finds nearest pages in other markets by
    cosine - the candidate set for Tier C.
  - **Tier C adjudication** (`Hrefl_Ai_Matcher`): Microsoft Copilot or Anthropic
    (selectable) chooses the true equivalent among the candidates or "none";
    it never invents a URL. Metadata-only by default; keys come from
    `HREFL_ANTHROPIC_KEY` / `HREFL_COPILOT_KEY` / `HREFL_EMBEDDING_KEY` (or the
    stored option). All output is a proposal - a human still confirms.
  - The matcher runs Tier A (slug) -> B -> C; cron warms embeddings first. New
    `hrefl_embedding` table and admin settings for both tiers.
- **Cursor-paginated alternates serve** - the hub's `/alternates` REST route
  pages a market with `?after=<id>` (`Hrefl_Distributor::serve_page`, ordered by
  the member id), and the client walks the `next` cursor and accumulates every
  page before one atomic store swap. A large market is never built, serialized,
  or transferred in a single response; the client caps the walk at 500 pages so
  a hostile cursor cannot loop. Small sites still resolve in one page.
- **CSV review round-trip** - a new "CSV review" hub page exports every mapping
  (`Hrefl_Csv`, formula-injection-safe cells) with a `decision` column, and
  accepts the edited file back; each `confirm` passes the same correctness guard
  as the on-screen queue (`Hrefl_Review_Actions`, now shared by both paths), and
  a row that would break its group is reported blocked, never half-applied.
- **Health dashboard** - a new "Health" hub page (`Hrefl_Monitor`) reports
  coverage, status totals, and structural issues: confirmed targets that failed
  validation, hreflang collisions inside a group, groups with no x-default, and
  confirmed members with nothing to link to. Read-only; fixes go through review.

## 0.2.0 - 2026-07-21 - security & scale hardening

Closes the findings from the cross-platform assessment. The plugin was a
condensed port; this brings the security-critical parts and the scale handling
to parity with the Drupal module.

### Breaking

- **Signing protocol v2.** The HMAC canonical string now covers the query
  string: `METHOD \n PATH \n QUERY \n TIMESTAMP \n sha256(body)`. A v1 client
  cannot authenticate against a v2 hub - upgrade every site together.

### Security

- **Ingest authorization.** The hub now rejects a payload whose `market`
  differs from the HMAC-verified `X-Hrefl-Market` header (403), so a signed
  client can no longer assert records for another market. Adds a 500-record cap
  (413). This closes the mapping-poisoning gap.
- **Per-market secrets + fail-closed.** `Hrefl_Markets::secret_for()` resolves a
  `HREFL_HUB_SECRET_<MARKET>` constant (or a stored per-market secret) before
  the shared `HREFL_HUB_SECRET`, and returns nothing for unknown markets. Set
  distinct per-market secrets for true tenant isolation.
- The alternates endpoint takes the market only from the signed header (the
  unsigned `?market=` fallback is gone), and the shared-secret admin field is a
  password input.

### Correctness & scale

- **Sitemap chunking.** Past 50,000 URLs `/hrefl-sitemap.xml` becomes a
  `<sitemapindex>` of numbered chunks (`/hrefl-sitemap.N.xml`), so large sites
  are no longer silently truncated. The `<priority>` is now configurable and
  `<lastmod>` is emitted from each page's changed time. Sitemap responses carry
  a 1-hour cache header.
- **Publish cursor.** Inventory publish walks the whole corpus across cron runs
  (cursor by post ID), instead of only the newest 200 posts.
- **Fair match pass.** Matching orders by a new `last_matched` column and stamps
  each processed member, so a backlog can no longer starve the tail or re-spend
  work on the same rows.
- **Store-wipe guard.** `Hrefl_Store::replace_all()` keeps the last known-good
  alternates when a pull returns nothing (hub down / all invalid), instead of
  blanking every page.
- A schema-upgrade guard (`admin_init`) runs `dbDelta` when the plugin version
  advances, so the new columns land without a manual re-activation.

## 0.1.0 - first release

Standalone all-WordPress plugin (client + hub by role): reciprocal hreflang
tags, own multilingual sitemap, country selector shortcode, signed REST sync,
per-market URL ownership, SSRF-safe validation, slug matching, and a review
queue.
