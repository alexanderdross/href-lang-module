# Hreflang Cross-Site (hrefl) - WordPress plugin

Cross-site `hreflang` for a **family of independent WordPress sites**: a
**client** on every site and a **hub** on one, connected by a signed REST API.
It emits reciprocal `hreflang` tags, a multilingual XML sitemap, and a
country/language selector - the WordPress port of the Drupal `hrefl` module.

> **Standalone by design.** This is for an all‑WordPress family. It does **not**
> interoperate with the Drupal hub (e.g. site A WordPress + site B Drupal is not
> a supported mix). Each platform runs its own hub.

## Install

1. Copy `hrefl-wp/` into `wp-content/plugins/` (or upload the zip via
   **Plugins → Add New → Upload**).
2. Activate it on **every** site in the family.
3. In **wp‑config.php** on every site, define the shared secret (same value):
   ```php
   define('HREFL_HUB_SECRET', 'a-long-random-string');
   ```
4. Open **Hreflang** in the admin menu and configure (below).

## Configure

**On the main site (the hub):** set *Role* = **Client + Hub**, add your markets
(one per line, `market|prefix`), e.g.
```
global|https://main.example/
de|https://de.example/
es|https://es.example/
```
A market prefix can be a path (`https://host/de/`) or a whole domain.

**On every site (including the hub):** set *Role*, *This market key* (e.g. `de`),
the *Hub REST URL* (`https://main.example/wp-json/hrefl/v1`), and the *Language
map* (`en|en-US`).

Then it runs on WP‑Cron: each site publishes its pages to the hub, the hub
proposes matches, you confirm them under **Hreflang → Review queue**, and each
site pulls its confirmed links and emits them.

- Sitemap: `https://<site>/hrefl-sitemap.xml`
- Selector: place the `[hrefl_selector]` shortcode, or use the alternates in your
  own template.

## Architecture ↔ Drupal parity

| WordPress class | Drupal counterpart | Role |
|---|---|---|
| `Hrefl_Signer` | `RequestSigner` + `SignedRequestAccessCheck` | HMAC sign/verify (identical canonical string) |
| `Hrefl_Validator` | `HreflangValidator` | hreflang correctness rules |
| `Hrefl_Store` | `AlternatesStore` | client local store |
| `Hrefl_Collector` | `InventoryCollector` | page inventory |
| `Hrefl_Hub_Client` | `HubClient` | signed transport |
| `Hrefl_Emitter` | `HreflangEmitter` + selector block | `wp_head` tags + selector |
| `Hrefl_Sitemap` | `SitemapGenerator` | multilingual sitemap |
| `Hrefl_Registry` | `Registry` | groups + members |
| `Hrefl_Markets` | `MarketRegistry` | ownership + host allowlist + secret |
| `Hrefl_Target_Validator` | `TargetValidator` | SSRF‑safe 200/canonical/index check |
| `Hrefl_Matcher` | `DeterministicMatcher` (slug tier) | URL‑pattern matching |
| `Hrefl_Distributor` | `Distributor` | resolve confirmed alternates |
| `Hrefl_Rest` | Ingest/Serve controllers | signed REST API |
| `Hrefl_Admin` | settings forms + review queue | admin UI |

## Security

- Every hub REST call is **HMAC‑signed** (`X-Hrefl-*` headers), verified with a
  5‑minute replay window and constant‑time comparison.
- **URL ownership** is enforced on ingest: a site can only assert URLs under its
  own market prefix.
- The serve endpoint is bound to the **signed** market.

## Parity scope (what this port includes vs. defers)

**Included (core loop):** client emit + sitemap + selector + signed sync; hub
registry + signed REST + URL‑ownership + **SSRF‑safe target validation**
(200/canonical/index, on cron) + slug matching + distributor + review queue
(confirm/reject with the correctness guard - a member can only be confirmed once
its target is validated).

**Deferred (present in the Drupal version, add later):** learned glossary + AI
Tier B/C (embeddings, Copilot/Anthropic) + translation, CSV round‑trip,
bulk/batch review, health dashboard, sitemap index, HTTP `Link` header, preview
thumbnails. On WordPress these map to the same extension points (a matcher tier,
an admin page, a cron job).

## Requirements

WordPress ≥ 6.2, PHP ≥ 8.0. No external dependencies (uses the WP HTTP API,
REST API, WP‑Cron, and `$wpdb`).
