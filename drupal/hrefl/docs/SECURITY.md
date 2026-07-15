# Security & Data Governance

The module moves content signals between backends and (optionally) out to AI
providers, and it writes data that ends up in every page's `<head>` and sitemap.
That makes it a meaningful attack surface. Principles: authenticate everything,
least privilege, validate all input, never trust a backend's claims blindly,
and minimize data sent to third parties.

## 1. Hub endpoint security (ingest / serve / CSV)

- **Authentication:** service-to-service auth between backends and hub - OAuth2
  client-credentials, signed requests (HMAC), or mutual TLS. No anonymous
  access.
- **Authorization / least privilege:** ingest is write-scoped to the calling
  backend; serve is read-only; CSV import/admin is behind explicit Drupal
  permissions and roles.
- **Rate limiting & size caps** on every endpoint; reject oversized batches.
- **Input validation:** strict schema validation on every payload; reject
  unknown fields; canonicalize and bound all URLs and codes.

## 2. Mapping-poisoning prevention (critical)

Because the hub aggregates URLs from multiple backends and republishes them as
live `hreflang`, a compromised or buggy backend could try to inject arbitrary
alternates.

- **URL-ownership enforcement:** a backend may only publish/claim URLs **under
  its own market prefix** on the canonical host (e.g. the DE backend may only
  assert `…/de/…` URLs). The hub rejects cross-claims.
- **Host allowlist:** every emitted alternate must resolve to the approved
  canonical host(s); anything else is dropped.
- **Signed payloads / provenance:** each membership records which backend
  asserted it and when; tampering is detectable and auditable.

## 3. SSRF-safe validation crawling

The hub (and any validation worker) fetches URLs to check 200/canonical/index
status. That fetch capability must be sandboxed:

- **Allowlist only** the family's public hostnames; refuse everything else.
- **Block internal ranges** (RFC1918, link-local, metadata IPs like
  169.254.169.254, loopback) and DNS-rebinding; resolve then pin the IP.
- **Do not follow redirects off-allowlist;** cap redirects, timeouts, and
  response size. No credentials on validation fetches.

## 4. AI provider data governance (pharma / GxP aware)

- **Minimize by default:** Tier-C LLM sees **metadata only** (title, meta,
  headings, breadcrumbs, candidate URLs) - never full body unless explicitly
  opted in per content type after review.
- **Approved, region-resident endpoints:** both Anthropic and Copilot must be
  the org-approved enterprise deployments with **data-retention disabled** and
  **EU data residency** where required; requests logged for audit. This is the
  ticket's "local instance" concern, generalized.
- **DLP/PII pre-flight:** scan outbound payloads for PII/regulated identifiers;
  block or redact before sending. Prefer a **self-hosted multilingual embedding
  model** so the Tier-B workhorse keeps content in-house entirely.
- **Prompt-injection hardening:** page content is untrusted input; the Tier-C
  *adjudication* prompt constrains the model to *choose among supplied candidate
  URLs or none*, and the *translation* job returns only a `{title, slug}` pair
  whose slug is sanitized to a safe URL token; all outputs are schema-validated
  and remain proposals - a page cannot instruct the matcher to emit
  an arbitrary URL.

## 5. Secrets management

- All API keys and inter-backend credentials via the Drupal **`key`** module /
  a secrets manager (Vault/KMS) - never in code, settings, or config export.
- **Rotation** supported; scoped keys per provider/backend; revoke on
  compromise.

## 6. CSV import safety

- **CSV/formula injection defense:** neutralize cells beginning with `= + - @`
  and control chars on export and import; treat all cells as text.
- **Schema + integrity validation** before applying; a failing group is
  reported and skipped, never half-applied.
- **Size/row limits, permission-gated, audited:** who imported what, when, and
  the resulting diff.

## 7. Access control & auditability

- Hub admin UI (review queue, thresholds, groups) behind RBAC; separate
  permissions for view / confirm / configure-thresholds / manage-providers.
- **Full audit trail:** every confirm/reject/regroup/threshold change and every
  auto-confirm is logged with actor, time, signals, and before/after.

## 8. Privacy / consent (frontend)

- The selector sets **no tracking cookies** and fires no analytics without
  consent; a remembered-choice cookie (if any) is strictly functional and
  consent-aware. No fingerprinting, no IP geo-profiling (there are no geo
  redirects at all).

## 9. Supply chain & platform hygiene

- Pin and review contrib/module versions (`metatag`, `key`, and any
  embedding/vector libraries; the module ships its own sitemap generator, so
  `simple_sitemap` is not a dependency here); track security advisories; keep
  Drupal core patched.
- Least-privilege DB and service accounts; network-segment the hub endpoints.
- Structured logging that **never** logs secrets or full page content.

## 10. Threat-model checklist

- [ ] All hub endpoints authenticated, authorized, rate-limited, schema-validated.
- [ ] Backends can only assert URLs under their own market prefix + approved host.
- [ ] Validation/crawl fetches are SSRF-sandboxed (allowlist, no internal IPs).
- [ ] AI: metadata-only default, approved region-resident endpoints, retention
      off, DLP pre-flight, prompt-injection-constrained, audited.
- [ ] Secrets in key module/vault, rotatable, never in VCS/config.
- [ ] CSV imports sanitized (formula injection), validated, permission-gated,
      audited.
- [ ] RBAC + full audit trail on all mapping and config changes.
- [ ] Selector is consent-aware, no geo profiling, no auto-redirect.
