# Security Test Plan

Test cases that verify the controls described in `SECURITY.md`. Each maps to an
automated test where possible (`SecurityTest`, `SignedRequestAccessCheckTest`,
`MarketRegistryTest`) and/or a manual check on staging. Severity reflects impact
if the control fails.

## 1. Authentication (hub API)

| # | Sev | Control | Test | Expected |
|---|-----|---------|------|----------|
| A1 | High | HMAC signature required | `SignedRequestAccessCheckTest::testUnsignedRequestIsForbidden` | unsigned request → 403 |
| A2 | High | Wrong secret rejected | `…testWrongSecretIsForbidden` | bad signature → 403 |
| A3 | High | Replay window | `…testStaleTimestampIsForbidden` | timestamp older than 5 min → 403 |
| A4 | High | Valid request allowed | `…testValidSignatureIsAllowed` | correct signature → allowed |
| A5 | Med | Constant-time compare | code review | uses `hash_equals`, not `==` |
| A6 | Med | Serve bound to signer | manual | `?market=` cannot override the signed `X-Hrefl-Market` |

## 2. Mapping-poisoning / URL ownership

| # | Sev | Control | Test | Expected |
|---|-----|---------|------|----------|
| B1 | High | Path-market ownership | `SecurityTest::testIngestRejectsCrossMarketUrls` | DE client cannot assert `…/us/…` URLs |
| B2 | High | Domain-market ownership | `MarketRegistryTest` | `es` market cannot claim the shared host, and vice-versa |
| B3 | High | Host allowlist for validation | `SecurityTest::testSsrfAllowlistRejectsForeignHost` | only family hosts are fetched |

## 3. SSRF-safe validation crawling

| # | Sev | Control | Test | Expected |
|---|-----|---------|------|----------|
| C1 | High | Host allowlist | `SecurityTest::testSsrfAllowlistRejectsForeignHost` | non-family host → not fetched |
| C2 | High | Block private/reserved IPs | manual + code review | RFC1918/link-local/metadata IP → refused (`FILTER_FLAG_NO_PRIV_RANGE`) |
| C3 | Med | No redirects, capped body/timeout | code review | `allow_redirects=false`, 10s timeout, 64 KB read cap, no auth |

> C2 is asserted structurally (the code path) in review; a live test needs a
> controlled internal host and should be run on staging, never against
> production internal ranges.

## 4. CSV import safety

| # | Sev | Control | Test | Expected |
|---|-----|---------|------|----------|
| D1 | Med | Formula-injection neutralized on export | `SecurityTest::testCsvExportNeutralizesFormulaInjection` | a cell starting `= + - @` is prefixed with `'` |
| D2 | Med | Confirm still guarded on import | `CsvImporterTest` | invalid/clashing rows are blocked, not applied |
| D3 | Low | Unknown/edited URLs ignored safely | `CsvImporterTest` | unknown URL → skipped, no error |

## 5. AI data governance

| # | Sev | Control | Test | Expected |
|---|-----|---------|------|----------|
| E1 | High | Metadata-only default | config + `AiMatcherBase::renderRecord` review | body only sent when `data_scope=full` (opt-in) |
| E2 | High | Approved region-resident endpoint | manual / policy | endpoint is the org-approved deployment; retention off |
| E3 | Med | Prompt-injection constrained | code review | model may only pick a supplied candidate or “none”; output schema-validated (`parseAnswer`) |
| E4 | Med | Slug from AI sanitized | `TranslationParseTest` | translated slug reduced to a safe URL token |

## 6. Secrets & access control

| # | Sev | Control | Check | Expected |
|---|-----|---------|-------|----------|
| F1 | High | No secrets in config/VCS | grep + review | config stores only key **names**; values via key module / env |
| F2 | Med | Admin behind permissions | route review | settings/markets = `administer hrefl hub`; review = `hrefl hub review mappings` |
| F3 | Low | No secrets/PII in logs | review | loggers record messages, not payloads or keys |

## 7. Privacy (frontend)

| # | Sev | Control | Check | Expected |
|---|-----|---------|-------|----------|
| G1 | Med | No geo auto-redirect | manual | switching is user-initiated only |
| G2 | Low | Selector sets no tracking cookie | manual | crawlable `<a>` links, no analytics without consent |

## Running

Automated portion:
```
vendor/bin/phpunit --group hrefl_hub
```
Manual portion: work through the ⚠/manual rows on staging and record results.
Do **not** run SSRF internal-range probes against production infrastructure.
