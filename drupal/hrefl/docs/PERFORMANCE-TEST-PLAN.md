# Performance Test Plan

Verifies the budget in `PERFORMANCE.md`: the mapping system must add ~zero cost
to serving a page, and all heavy work stays off the request path. Combines
structural guarantees (asserted by architecture/tests) with staging
measurements.

## 1. Budget (targets)

| Metric | Target | How measured |
|--------|--------|--------------|
| Added server time per page from hreflang | **< 2 ms** | one indexed local read; profile with XHProf/Blackfire on staging |
| Cross-backend calls at render | **0** | structural (see §2) |
| Added `<head>` weight (where head tags on) | minimal | sitemap carries the bulk; inspect payload |
| Selector blocking requests / CLS | **0 / 0** | Lighthouse; crawlable `<a>`, static data |
| Sitemap generation | off-peak, incremental, chunked | cron logs; chunk index at 50k |
| Mapping freshness (event → live) | minutes | timestamp from publish to confirmed serve |

## 2. Structural guarantees (no runtime call on the hot path)

These are guaranteed by design and verifiable by code review / architecture:

- **Render reads only the local store.** `HreflangEmitter` depends on
  `AlternatesStore` + `HreflangValidator` - **not** on `HubClient`. A page render
  therefore cannot make a cross-backend HTTP call.
- **Indexed single read.** `hrefl_client_alternates` is primary-keyed on
  `url_hash`; head, selector, feed, and sitemap all read by that key.
- **Cache-tag invalidation is per group.** A mapping change invalidates only
  `hrefl_group:UUID`, not the whole site.
- **All matching/validation/AI is queued**, never in a visitor request
  (`hook_cron`, queue workers, on-demand Drush).
- **Sitemap paginates** - `AlternatesStore::all($limit, $offset)` +
  `SitemapGenerator` chunk into a `sitemapindex` beyond the per-file cap.

> A cheap regression guard for the “no hub on render” rule: assert (reflection)
> that `HreflangEmitter`’s constructor does not depend on `HubClient`. Kept as a
> documented invariant here; add to `RegressionTest` if desired.

## 3. Staging measurements (manual)

| # | Scenario | Method | Pass |
|---|----------|--------|------|
| P1 | Page render overhead | Blackfire/XHProf a page with vs. without the module enabled | added time < 2 ms |
| P2 | Cache behaviour | Load a mapped page twice; confirm render/dynamic-page cache hit | 2nd load served from cache |
| P3 | Selector CWV | Lighthouse on a page with the selector block | 0 CLS from the selector, no blocking request |
| P4 | Sitemap size/time | Generate the sitemap for the full inventory off-peak | within 50k/50MB per file; index used beyond that |
| P5 | Sync throughput | Run ingest for N pages; watch queue drain | no request-path impact; queue backs off under load |

## 4. Scale / load (optional, pre-large-rollout)

- **Vector search** - `VectorStore::nearest()` is an exact brute-force cosine
  scan. Fine at a few thousand pages; benchmark at your real inventory size and
  swap in a true ANN index (pgvector / vector DB) behind the same `nearest()`
  method if the scan time grows.
- **Registry size** - index review on `hrefl_group_member`
  (`group_uuid`, `market+status`, `hreflang`, `path_key`) already present;
  re-check `EXPLAIN` on the serve/monitor queries at scale.
- **AI cost/quotas** - Tier C runs only on ambiguous candidates; add budget caps
  + a Tier-B-only fallback before a large rollout.

## 5. Regressions to watch

- Any new render-time dependency on `HubClient` or a sibling backend → **fail**.
- Any un-paginated `all()` read feeding the sitemap → memory risk at scale.
- Head-tag emission enabled site-wide on a very large family → prefer sitemap as
  the primary carrier and enable head tags per section only.
