# Source Ticket - "href-lang meta-tags"

Captured from the Jira export in the project folder (an HTML
export). This is the originating requirement; the concept in `docs/CONCEPT.md`
is the response to it.

## Header

- **Title:** href-lang meta-tags
- **Project:** Web Forward – Acquia · **Epic:** SEO · **Sprint:** Acquia Sprint 21
- **Type:** Story · **Priority:** Medium · **Story Points:** 5
- **Status:** Discovery · **Resolution:** Unresolved
- **Labels:** BE, BI-Clarification, Blocked, Lite
- **Reporter:** Samantha Tonner · **Assignee:** James Massender
- **Created:** 12 Aug 2025 · **Updated:** 09 Jul 2026

## Description (verbatim intent)

- **Requirement:** As a site owner I want all my content interlinked via
  `hreflang` meta-tags with the corresponding content on other sites of the
  bi-family, so that my SEO ranking doesn't drop and my visitors find the correct
  page in their country/market using a global country-selector.
- **Need:** Switching to a new country should keep the context
  (`bi.com/us/about-us` → `bi.com/de/ueber-uns`), and pages should be interlinked
  with `hreflang` metatags to increase SEO ranking.
- **ROPU/OPU:** Corporate.
- **Reference:** *Multilingual and multinational site annotations in Sitemaps*
  (Google Search Central).

## Comments (chronological)

- **Aman Srivastava (26 Nov 2025):** One possible solution is a **CSV-format
  mapping** of language-specific URLs for the same content across sites. The CSV
  is referenced on a **scheduled cron** and links are updated on each site. To be
  discussed on the Leads sync call.
- **Alexander Dross (26 Nov 2025, relayed):** Will share the **HREFLANG POC** as
  a starting point for a **custom module** that could leverage a **local ChatGPT
  instance** to find common content across multiple **sitemaps**, create a
  mapping, and **inject the required hreflang tags** into the `<head>` of all
  relevant pages.
- **James Massender (09 Jul 2026):** Add this to the next **TA discussion**.
  Aman's proposal is worth considering since the max number of languages per site
  is likely small (~3–4), whereas the earlier **BICOM** case was more complex.

## How the concept answers the ticket

| Ticket signal | Concept response |
|---------------|------------------|
| CSV mapping + cron (Aman) | CSV kept as the **review/interchange** format on top of a hub registry; clients sync on **cron**. |
| Local ChatGPT / sitemap matching (POC) | **AI matcher** as a *proposer*, reading inventories/sitemaps; **Anthropic + Copilot** providers; approved/enterprise endpoints for the "local" concern. |
| Inject hreflang into `<head>` | `hrefl_client` **head injector**, rule-enforced. |
| Small language count per site (~3–4) | Central-group model scales cleanly to a few markets and beyond; onboarding is "point a client at the hub". |
| Increase/protect SEO ranking | Strict correctness rules (`docs/HREFLANG-RULES.md`); safe degradation; never emit broken/unconfirmed links. |
| Country-selector keeps context | Module provides the cross-market **mapping** the selector consumes. |
