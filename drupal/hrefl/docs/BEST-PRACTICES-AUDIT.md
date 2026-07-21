# PHP / Drupal Best-Practices Audit

A self-audit of the codebase against Drupal and PHP engineering standards, plus
the list of correctness fixes that regression tests now lock in. Run the
automated tooling (`phpcs`, `phpstan`) on a real checkout to confirm - this is a
structural review, not a substitute for those tools.

## 1. Standards checklist

| Item | Standard | Status | Notes |
|------|----------|--------|-------|
| Strict types | `declare(strict_types=1)` in every PHP file | ✅ | all `src/` classes |
| Typed properties + constructor promotion | modern PHP 8.1 | ✅ | `private readonly` throughout |
| Dependency injection | no `\Drupal::` in services | ✅ | `\Drupal::` used only in hooks (`.module`), batch ops, and update hooks, where it is expected |
| Services declared in `*.services.yml` | Drupal DI | ✅ | every service registered; optional deps use `@?service` |
| Config has schema | `config/schema/*.yml` | ✅ | client + hub settings fully typed |
| Route access control | permission or access check | ✅ | admin = `_permission`; machine API = `_hrefl_signed` |
| Plugin discovery via attributes | D10 attributes | ✅ | AI matcher + embedding provider + block + queue worker |
| DB schema + `hook_update_N` | install/update hooks | ✅ | updates `9001`–`9004`; run `drush updatedb` |
| Content vs config separation | mapping = content, settings = config | ✅ | registry in DB tables, settings in config |
| Cache tags / contexts | render caching | ✅ | per-group `hrefl_group:UUID` tag; selector caches on `url.path` |
| No secrets in code/config | key module / env | ✅ | secrets via `key.repository` or env; config stores only key **names** |
| Translatable UI strings | `t()` / `$this->t()` | ✅ | forms/controllers/messages |
| CSRF on state-changing links | `_csrf_token` | ✅ | single-row review actions |
| Queue for off-request work | Drupal Queue | ✅ | client publish, target validation |
| Coding standards | `phpcs Drupal,DrupalPractice` | ⏳ | **run on a real checkout** |
| Static analysis | `phpstan` | ⏳ | **run on a real checkout** |

## 2. Deliberate patterns worth noting

- **Optional key module** - `AiMatcherBase`, `EmbeddingProviderBase`,
  `MarketRegistry`, `RequestSigner` accept `key.repository` via
  `@?key.repository` (null when the module is absent) and fall back to env vars.
  This keeps the module installable without a hard dependency.
- **One guard, many callers** - `ReviewActions::confirm()` is the single place
  confirmation correctness is enforced; the review UI, bulk batch, and CSV
  import all route through it. `MarketRegistry` is the single source for
  ownership + host allowlist + secret. `HreflangValidator` is the single emit
  gate on the client.
- **Signature parity** - the client `RequestSigner` and the hub
  `SignedRequestAccessCheck` document the identical canonical string
  (`METHOD\nPATH\nTIMESTAMP\nsha256(body)`); they live in separate modules by
  design and are each covered by tests.

## 3. Correctness fixes locked by regression tests

These bugs were found and fixed during development; `RegressionTest` guards them:

| Fix | Was | Now | Guarded by |
|-----|-----|-----|------------|
| CSV export completeness | only `proposed`/`held` rows exported | `Registry::allMembers()` exports every status | `RegressionTest::testExportIncludesConfirmed` |
| Member lookup | `MappingEngine`/CSV scanned only need-match rows → a confirmed member wasn’t found | `Registry::memberIdForUrl()` direct lookup | `RegressionTest::testMemberIdForUrlFindsConfirmed` |
| Engine provenance | Tier-A hit hardcoded `key`/`1.0` | honors matcher’s `matched_by`/`confidence` (glossary = 0.7 → review) | `PatternMatchingTest` (coalescing at 0.7) |
| Empty-group cleanup | re-homed members orphaned singleton groups | `Registry::deleteEmptyGroups()` after a match pass | `RegressionTest::testEmptyGroupsCleaned` |
| Strict-types route param | `int` type-hint on a route param throws under strict types | controllers take `string` and cast | (review/sitemap controllers) |

## 4. Follow-ups (non-blocking)

- Collect Tier-A identity signals (`schema.org @id`, existing hreflang) in
  `InventoryCollector` to strengthen deterministic matching.
- Add a Functional (Browser) test for the selector block + admin forms.
- Add an integration test for a Tier-C provider against a stub HTTP server.
- Consider a thin adapter/version guard around any future `simple_sitemap` or
  `metatag` integration.
