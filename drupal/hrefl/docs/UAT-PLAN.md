# UAT Plan - User Acceptance Testing

Manual, end-to-end scenarios written from the **operator’s** point of view
(admin / editor / SEO), to sign off the module on a real staging environment.
Each case has a priority (**P1** = must pass to go live, **P2** = important,
**P3** = nice), preconditions, steps, and the expected result. Record
pass/fail + notes per run.

Environment: at least **two** backends (e.g. Global `/` + Germany `/de/`), both
with `hrefl_client`, the hub on Global, a shared `HREFL_HUB_SECRET`, cron
runnable on demand (`drush cron`).

## Phase 0 - Proof of life

| # | P | Scenario | Steps | Expected |
|---|---|----------|-------|----------|
| 0.1 | P1 | Head tags from a seed | `drush hrefl:seed …/seed/example.seed.json` on Global + DE; view-source `/about-us` and `/de/ueber-uns` | Both pages show the full, reciprocal `<link rel="alternate" hreflang>` set incl. one `x-default`; URLs absolute |
| 0.2 | P1 | Inspect via CLI | `drush hrefl:show https://…/de/ueber-uns` | Prints the same tag set as the page |
| 0.3 | P2 | Safe degradation | Visit a page with no mapping | No hreflang tags emitted (no guessing), page renders normally |

## Phase 1 - Hub + client contract

| # | P | Scenario | Steps | Expected |
|---|---|----------|-------|----------|
| 1.1 | P1 | Client → hub sync | Configure client (hub URL, market, secret); `drush cron` | Hub shows the site’s pages as `proposed` in the review queue |
| 1.2 | P1 | Signed transport works | With matching secret on hub + client | Sync succeeds; with a **wrong** secret, hub rejects (403) and nothing ingests |
| 1.3 | P1 | URL ownership | Make the DE client publish a `…/us/…` URL (tamper) | Hub **rejects** that record (`rejected` count > 0); it never enters the registry |
| 1.4 | P1 | Confirm publishes | In the review queue, confirm a mapping; run client cron | The confirmed alternates appear in the target pages + sitemap on next sync |
| 1.5 | P1 | Only clean targets | Confirm a mapping whose target is a 404/noindex | Confirmation is **blocked/skipped**; nothing broken goes live |
| 1.6 | P2 | Reject removes | Reject a proposal | It never appears in output; state = `rejected` |

## Phase 2 - Automation + sitemap

| # | P | Scenario | Steps | Expected |
|---|---|----------|-------|----------|
| 2.1 | P1 | Auto URL-pattern proposal | Publish same-slug pages in 2 markets; `drush cron` | They appear grouped as a single `proposed` mapping (matched_by = glossary) |
| 2.2 | P2 | Glossary learns | Confirm a cross-language pair (e.g. ueber-uns↔about-us); publish another such page; cron | The new page is matched automatically next round |
| 2.3 | P1 | Multilingual sitemap | Open `https://<site>/hrefl-sitemap.xml` | Valid `urlset` with `xhtml:link` alternates + `lastmod` + `priority` |
| 2.4 | P2 | Sitemap index at scale | Lower `sitemap_chunk_size` to 2 (test) with ≥3 URLs | Entry point returns a `sitemapindex`; `…/hrefl-sitemap.0.xml` etc. return chunks |
| 2.5 | P3 | AI translation help | Configure a provider; `drush hrefl-hub:translate-match` | New proposals carry a suggested title/slug shown in the queue |

## Phase 3 - Operate

| # | P | Scenario | Steps | Expected |
|---|---|----------|-------|----------|
| 3.1 | P1 | Country selector | Place the “Country / language selector (hreflang)” block | Visitor can switch market to the **equivalent** page, not the homepage |
| 3.2 | P1 | Bulk review | Select several rows → “Confirm selected” → confirmation page → proceed | Progress bar runs; summary shows confirmed / skipped counts |
| 3.3 | P2 | CSV round-trip | Export CSV, set `decision` in a spreadsheet, Import CSV | Applied count matches; invalid rows reported as blocked |
| 3.4 | P1 | Health dashboard | Open the Health tab | Coverage % shown; any broken/one-sided/duplicate-code issues listed |
| 3.5 | P2 | HTTP Link header | Enable it; request a PDF URL | Response carries `Link: …; rel="alternate"; hreflang=…` |

## Onboarding

| # | P | Scenario | Steps | Expected |
|---|---|----------|-------|----------|
| 4.1 | P1 | Add a path market | Markets tab → add `fr` = `https://host/fr/` | Market saved; client with market `fr` can sync |
| 4.2 | P1 | Add a domain market | Markets tab → add `es` = `https://host.es/` | Ownership + validation accept `…es` URLs; other markets can’t claim them |
| 4.3 | P2 | Generate secret | “Generate a shared secret” | A random secret is shown once for copying |

## Sign-off

- [ ] All **P1** cases pass on staging.
- [ ] No regressions in existing site rendering/performance.
- [ ] SEO reviewer confirms the per-market hreflang codes + x-default.
- [ ] Security checklist (`SECURITY-TEST-PLAN.md`) complete.
- [ ] Data-governance sign-off for any AI endpoint used.

Tester: ____________  Date: __________  Build/commit: __________
