# QA Assessment

Status snapshot of quality for the **hrefl** module: what is built, what is
tested, how it is tested, the known gaps, and the exit criteria before calling
it production‑ready. Companion documents: `UAT-PLAN.md`,
`BEST-PRACTICES-AUDIT.md`, `SECURITY-TEST-PLAN.md`,
`PERFORMANCE-TEST-PLAN.md`.

> **Important:** the automated tests below are written but have **not been
> executed in this workspace** (no PHP runtime, no Drupal core present). They
> must be run on a real Drupal 10.3/11 install. See §5.

## 1. Scope under test

| Area | Module | State |
|------|--------|-------|
| Head-tag emission, validation, safe degradation | `hrefl_client` | built + tested |
| Own multilingual sitemap (+ index, lastmod, priority) | `hrefl_client` | built + tested |
| HTTP `Link` header (non-HTML) | `hrefl_client` | built + tested |
| Country/language selector block + JSON feed | `hrefl_client` | built (no automated test) |
| Signed client↔hub transport | both | built + tested (verify) |
| Registry, ingest/serve, URL-ownership | `hrefl_hub` | built + tested |
| Tiered mapping engine A→B→C | `hrefl_hub` | A tested; B seam tested; C untested vs live API |
| Review queue (bulk, batch, confirm page), CSV round-trip | `hrefl_hub` | built + tested |
| Monitor / health dashboard | `hrefl_hub` | built + tested |
| Markets admin + multi-domain ownership | `hrefl_hub` | built + tested |

## 2. Test strategy (the pyramid)

- **Unit** - pure logic, no container: code/URL validation, cosine, slug
  normalization, translation parsing, structural group validation.
- **Kernel** - services + database + config, no browser: matching/coalescing,
  serve/confirm guard, CSV, vector store, monitor, access check, forms.
- **Functional / Browser** - *not yet written*; requires a full site. Covered by
  the manual UAT script (`UAT-PLAN.md`) until automated.
- **Static** - Drupal coding standards (`phpcs`) + `phpstan`; run in CI.

## 3. Automated test inventory

**Unit (`tests/src/Unit`)**

| Test | Covers |
|------|--------|
| `hrefl_client HreflangValidatorTest` | code normalization, absolute-URL check, dedupe, one x-default |
| `hrefl_hub MappingValidatorTest` | duplicate codes, ≤1 x-default, confirm guard |
| `hrefl_hub SlugNormalizerTest` | leaf-slug extraction, casing, url-decode |
| `hrefl_hub TranslationParseTest` | AI translation JSON parse + slug sanitization |
| `hrefl_hub CosineTest` | cosine similarity edge cases |

**Kernel (`tests/src/Kernel`)**

| Test | Covers |
|------|--------|
| `hrefl_client SeedAndEmitTest` | Phase-0 seed → validated reciprocal emission |
| `hrefl_client SitemapTest` | urlset content + chunked sitemap index |
| `hrefl_client LinkHeaderTest` | Link header on non-HTML, skipped on HTML |
| `hrefl_hub ReviewAndServeTest` | confirm guard + serve reciprocity |
| `hrefl_hub PatternMatchingTest` | slug coalescing + glossary bridge + feedback loop |
| `hrefl_hub CsvExportTest` | title + AI translation surfaced in export |
| `hrefl_hub CsvImporterTest` | confirm/reject/leave, skip unknown, block invalid |
| `hrefl_hub VectorStoreTest` | nearest-neighbour, market exclusion, threshold |
| `hrefl_hub MonitorTest` | coverage + issue detection |
| `hrefl_hub ReviewBatchTest` | bulk batch guard + counters across chunks |
| `hrefl_hub MarketRegistryTest` | ownership (path + domain), host allowlist |
| `hrefl_hub SignedRequestAccessCheckTest` | HMAC valid/invalid/stale/unsigned |
| `hrefl_hub MarketsFormTest` | add/remove markets in config |
| `hrefl_hub RegressionTest` | locked-in fixes (see `BEST-PRACTICES-AUDIT.md`) |
| `hrefl_hub SecurityTest` | ownership rejection, CSV injection, SSRF allowlist |

## 4. Risk-based coverage

| Risk (if it fails) | Severity | Mitigation / test |
|--------------------|----------|-------------------|
| A wrong/broken hreflang goes live → SEO damage | **High** | confirm guard + target validation + `ReviewAndServeTest`, `MonitorTest` |
| A backend poisons another market's mapping | **High** | URL-ownership + `SecurityTest`, `MarketRegistryTest` |
| Unauthenticated hub access | **High** | HMAC access check + `SignedRequestAccessCheckTest` |
| Page render slowed by cross-backend calls | **High** | local-store-only read (structural); `PERFORMANCE-TEST-PLAN.md` |
| CSV import corrupts data / spreadsheet injection | Med | `CsvImporterTest`, `SecurityTest`, import validation |
| Matching mislinks pages | Med | proposals are review-gated; `PatternMatchingTest` |
| Sitemap exceeds spec limits | Med | chunked index + `SitemapTest` |

## 5. Exit criteria (definition of done)

On a real Drupal 10.3/11 install:

1. `composer install`; `drush en hrefl_client hrefl_hub`; **`drush updatedb`**
   (schema updates: `path_key`, `title`, `image`, client `lastmod`,
   `hrefl_embedding`, `markets` config).
2. **`vendor/bin/phpunit`** - all listed tests green.
3. **`phpcs --standard=Drupal,DrupalPractice web/modules/custom/hrefl`** - no
   errors; **`phpstan`** at the project level - no new errors.
4. **UAT script** (`UAT-PLAN.md`) - all P1 scenarios pass.
5. **Security checklist** (`SECURITY-TEST-PLAN.md`) - all items pass.
6. **Performance budget** (`PERFORMANCE-TEST-PLAN.md`) - met on staging.

## 6. Known gaps / not covered

- **No Functional/Browser tests** - the selector block, admin forms rendering,
  and end-to-end sync are covered only by the manual UAT script.
- **Tier C (Copilot/Anthropic) untested against a live endpoint** - only the
  request-building and response-parsing logic is unit-tested; provider calls
  need an integration test with a stub HTTP server or a sandbox key.
- **Tier B embeddings inert** until an embedding endpoint is configured; the
  store + cosine are tested, the provider call is not.
- **Tier-A identity signals** (schema.org `@id`, existing hreflang) are defined
  in the matcher but not yet collected by `InventoryCollector` - matching leans
  on slug + glossary until then.
- **Load/soak testing** not done - see `PERFORMANCE-TEST-PLAN.md` for the plan.
