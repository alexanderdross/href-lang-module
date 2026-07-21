# Changelog - hrefl (Drupal)

All notable changes to the Drupal module. Dates are ISO (YYYY-MM-DD).

**Versioning.** `v1` is the original module as first shipped; `v2` is the
current, security-reviewed line and the only one under active development. v1 is
frozen - it stays available (git tag `v1.0.0`) as a reference, but new work lands
on v2.

## 2.0.0 - 2026-07-21 - security & correctness hardening (v2 · latest)

The security- and code-quality-reviewed line (Factorial review). No feature
changes versus v1; the module's behaviour is the same, with the defects below
fixed. Tests were updated in lockstep, and the suites stay green on Drupal 11 /
PHP 8.3. The major bump reflects the breaking items below, not a rewrite.

### ⚠️ Breaking (fresh installs only - nothing is deployed yet)

- **Signing protocol v2.** The HMAC canonical string now covers the query
  string: `METHOD \n PATH \n QUERY \n TIMESTAMP \n sha256(body)` (QUERY is
  key-sorted and re-encoded so both sides derive the same bytes). A v1 client
  cannot authenticate against a v2 hub - upgrade client and hub together.
- **Sitemap route renamed** `/hrefl/sitemap.xml` → `/hrefl-sitemap.xml` (and the
  chunks `/hrefl-sitemap.N.xml`), avoiding a collision under the `/hrefl` path.
  Re-submit the new URL in Search Console.
- **Update hooks renumbered** to the 10xxx range, with a new `hrefl_hub_update_10005`
  for the `last_matched` column. Run `drush updatedb` after upgrading.

### Critical

1. **Ingest authz bypass** - `IngestController` now rejects a payload whose
   `market` differs from the HMAC-verified `X-Hrefl-Market` header (403), so a
   site can no longer sign as itself but assert records for another market. Adds
   a 500-records/request cap (413). New kernel test covers the mismatch.
2. **MySQL install failure** - the unique key on `url varchar(2048)` is now a
   191-char prefix key (the ceiling and the `url_hash` upgrade path are
   documented in a comment).
3. **Store wipe** - `AlternatesConsumer` no longer calls `replaceAll([])` when
   every page fails validation; last known-good alternates survive a bad pull.

### Security

4. `secretFor()` fails closed: unknown markets get no secret, and a
   configured-but-unresolvable `key_name` no longer falls through to the shared
   env secret (which would silently downgrade every market to one key).
5. HMAC canonical string now covers the query string (see Breaking above);
   client signer, hub check and tests updated together. The replay limitation
   (no nonce store) is documented.
6. The `import.csv` POST route now requires `_csrf_request_header_token`.
7. `CsvImportForm` uses `managed_file` + a `FileExtension` validator (the temp
   file is deleted after use); `drupal:file` added as a dependency.
8. `ServeController`'s referer-style `?market=` fallback is deleted - the market
   comes only from the signed header.

### Correctness

9. The queue worker now processes its payload: it publishes the one changed
   entity and retires deleted/unpublished ones (`indexable: 0`); publish
   failures propagate so the queue retries.
10. Cron client publish uses a nid cursor (`collectNext()`), so sites of any size
    fully sync across successive runs.
11. The hub match pass is fair: a new `last_matched` column orders never-matched
    rows first, ending repeated LLM spend on the same first 200 rows.
12. `embedPass()` embeds only members missing a vector (left join), not the same
    first 200 each run.
13. Re-ingest no longer downgrades `confirmed`/`rejected` members (automation may
    refresh data, not status).
14. `CsvImporter` parses with `fgetcsv()` over a stream, so quoted multi-line
    titles round-trip.
15. The validator accepts `zh-Hans`, `zh-Hant-TW`, `es-419` with proper BCP 47
    case normalization.
16. `cosine()` returns 0 for mismatched dimensions.

### Caching

17. Selector endpoint: referer fallback removed (400 without `?url=`); now a
    `CacheableJsonResponse` with a `url.query_args:url` context and store tags.
18. Selector block: strips the query string before lookup, an empty result
    carries full `#cache`, and the `aria-label` is translated.
19. New store-wide `hrefl_alternates` cache tag: pages/blocks rendered before
    their group exists are invalidated on the next pull (`AlternatesStore`
    invalidates it on every swap).
20. Misc: `LinkHeaderSubscriber` skips non-200s and strips query strings; the
    umbrella `hrefl.info.yml` is `hidden: true`; typed `Registry` params in
    `.module`; the upsert race and `JSON_THROW_ON_ERROR` are handled.

### Reviewed but deliberately not changed

Noted, not blocking: a nonce store for full replay prevention, streaming CSV
export, a review-queue pager past 500 rows, and `HubSettingsForm` floor ≤
confirm validation.

## 2.1.0 - 2026-07-21 - polish & SSRF pinning

- **Human-readable selector labels.** The country/language block and the JSON
  selector feed now show "English (United States)" / "Deutsch" (endonyms) via a
  new `HreflLocale` helper, instead of the raw `en-US` code; `hreflang`/`lang`
  stay the machine code.
- **SSRF DNS pinning.** `TargetValidator` pins the validation fetch to the public
  IP it just vetted (`CURLOPT_RESOLVE`), closing the TOCTOU/DNS-rebinding window
  between the safety check and the request. Falls back to the host allowlist on
  non-curl transports.

## 1.0.0 - 2026-07-15 - first release (v1 · original)

The original module, frozen at git tag `v1.0.0`. The complete client + hub
architecture: reciprocal hreflang tags, the own multilingual XML sitemap, the
country selector, the tiered matching engine (deterministic → embeddings → LLM
adjudication via Copilot or Anthropic), and the human review loop. Superseded by
v2; kept for reference only.
