# Changelog - hrefl (WordPress)

All notable changes to the WordPress plugin. Dates are ISO (YYYY-MM-DD).

## Unreleased (0.3.0) - feature parity with Drupal

Closing the documented condensed-port gaps, landing in stages:

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
