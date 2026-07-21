# TA Decision One-Pager - href-langs-module

For: Technical Architecture discussion · Prepared: 15 July 2026

Six decisions gate the build. Each has a recommendation; please confirm or amend
in the meeting. Full rationale lives in `docs/RECOMMENDATIONS.md`,
`docs/SECURITY.md`, and `docs/HREFLANG-RULES.md`.

## At a glance

| # | Decision | Recommended | Owner | Blocks |
|---|----------|-------------|-------|--------|
| 1 | Cross-domain scope | Design for multi-domain now | TA / Architecture | Security model, onboarding |
| 2 | Fallback when no equivalent | Link nearest parent, marked | SEO / Editorial | Selector, sitemap |
| 3 | Auto-confirm at launch | Review-all first, relax later | Editorial / SEO | Phase 2 rollout |
| 4 | hreflang codes + x-default | Confirm table; Global = x-default | SEO | Go-live |
| 5 | Approved AI endpoints + model | Self-hosted embeddings; approved LLM | Legal / IT Security | Phase 2 |
| 6 | Regulated-content handling | Classify: review-only or exclude | Legal / Medical | Go-live |

## Detail

### 1. Cross-domain vs path-prefix scope
**Question.** Must the module link equivalents across separate domains or brands
(the "bi-family", the more complex BICOM case), or only across path-prefixed
markets on one host (`/de/`, `/us/`, ...)?
**Options.** (a) Path-prefix only, one host. (b) Multi-domain / cross-brand.
**Recommendation.** Design for multi-domain from the start (the model already
uses absolute URLs), but confirm which domains are in scope now vs later.
**Why it matters.** Drives the security model (URL-ownership, host allowlist),
market onboarding, and effort.
**Decision: ................................................**

### 2. Fallback / partial-mapping policy
**Question.** What happens when a page has no exact equivalent in a target market?
**Options.** (a) Emit no link. (b) Link to the nearest parent/section, visibly
marked. (c) Link to the market home, marked.
**Recommendation.** Nearest parent/section, visibly marked; never emit a dead
hreflang; the selector shows a labeled fallback.
**Why it matters.** SEO correctness and selector UX; avoids broken cross-links.
**Decision: ................................................**

### 3. Auto-confirm at launch vs review-all-first
**Question.** Should high-confidence matches go live automatically at launch, or
should everything be human-reviewed first?
**Options.** (a) Review everything first, relax later. (b) Auto-confirm
high-confidence from day one.
**Recommendation.** Review-all first for the initial weeks to build trust, then
enable auto-confirm per section as precision is proven.
**Why it matters.** Balances editorial workload against speed and risk appetite.
**Decision: ................................................**

### 4. hreflang code table + x-default owner
**Question.** The exact hreflang code per market, and which page is `x-default`.
**Options.** e.g. `/de/` as `de` vs `de-DE`; Global as `en` + `x-default` vs
`x-default` only.
**Recommendation.** Adopt the draft table in `docs/HREFLANG-RULES.md`; Global
English page as `x-default`; confirm codes with SEO.
**Why it matters.** Ranking correctness; must be right before go-live.
**Decision: ................................................**

### 5. Approved AI endpoints + embedding model
**Question.** Which AI endpoints are approved for BI data, and which embedding
model for Tier B? (The provider *choice* is settled: **Copilot and Anthropic are
both fully supported and selectable in config, neither mandated** - Copilot is
the shipped default. What remains open is endpoint approval and the embedding
model.)
**Options.** (a) Approved, region-resident enterprise endpoints, retention off.
(b) Fully self-hosted / on-prem.
**Recommendation.** Self-hosted multilingual embeddings for Tier B (keeps content
in-house); approved region-resident enterprise endpoints for Tier C (either
provider), metadata only.
**Why it matters.** Data-governance and compliance sign-off; blocks Phase 2.
**Decision: ................................................**

### 6. Regulated-content handling
**Question.** How to treat medical or regulated pages that must not be presented
as equivalents across markets?
**Options.** (a) Exclude from mapping. (b) Review-only (never auto-confirm).
(c) Classify and gate per content type.
**Recommendation.** A content-classification gate: regulated pages are
review-only or excluded, with strict AI data controls.
**Why it matters.** Compliance and legal risk (pharma / GxP).
**Decision: ................................................**

## After the meeting

Record the confirmed decisions, update `CLAUDE.md` §8 and
`docs/RECOMMENDATIONS.md`, and unblock Phase 1 (hub + client) and Phase 2
(automated mapping engine) in `docs/ROADMAP.md`.
