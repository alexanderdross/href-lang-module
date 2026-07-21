# Worked example: adding an own-domain market (Spain / cardiorrenal.es)

A concrete, copy-paste example of onboarding a market that lives on its **own
domain** and its **own language** (Spanish), served by its own Drupal install -
here `https://www.cardiorrenal.es/`. It sits next to the path-prefix markets
(`/de/`, `/us/`, `/ca/fr/`) with no code change: the `prefix` is just a full
domain instead of a path.

> Drupal-to-Drupal only: the Spanish site runs `hrefl_client` and talks to the
> same Drupal hub, so reciprocity is guaranteed by construction. A WordPress site
> would use the standalone WP plugin in an all-WordPress family, not this hub.

## 1. Hub: register the market

Add `es` to `hrefl_hub.settings` (Configuration UI's "Add market" screen, or the
config below). The SSRF host allowlist and URL-ownership check derive
automatically from each market's `prefix`, so listing the domain here is all that
is needed to let the hub validate and own `www.cardiorrenal.es` URLs.

```yaml
# config/hrefl_hub.settings.yml (excerpt)
canonical_host: 'https://pro.boehringer-ingelheim.com'
markets:
  global:
    prefix: 'https://pro.boehringer-ingelheim.com/'
    key_name: 'hrefl_hub_secret_global'
  de:
    prefix: 'https://pro.boehringer-ingelheim.com/de/'
    key_name: 'hrefl_hub_secret_de'
  # ... us, ca ...
  es:
    prefix: 'https://www.cardiorrenal.es/'   # a whole domain, not a path prefix
    key_name: 'hrefl_hub_secret_es'          # key module key holding this market's HMAC secret
```

- `key_name` points at a **key module** key (never a literal secret in config).
  Create one per market for real tenant isolation.
- Nothing else is needed for the allowlist - `MarketRegistry::allowedHosts()`
  reads `www.cardiorrenal.es` straight from the `prefix`.

## 2. Client: configure cardiorrenal.es

On the Spanish site's `hrefl_client`:

```yaml
# config/hrefl_client.settings.yml (on www.cardiorrenal.es)
hub_base_url:    'https://pro.boehringer-ingelheim.com/hrefl-hub/api/v1'
market:          'es'
hub_key_name:    'hrefl_hub_secret_es'       # the same shared secret, via key module
site_base_url:   'https://www.cardiorrenal.es'
emit_head_tags:  true
emit_link_header: true
sitemap_enabled: true
hreflang_map:
  es: 'es'                                    # single-language market -> es (or es-ES)
```

The client then, on cron: publishes its page inventory to the hub, pulls its
resolved alternates, and emits reciprocal `<link rel="alternate" hreflang="…">`
in the head (plus the sitemap and the selector feed).

## 3. Seed a few confirmed mappings (Phase 0, optional)

Cross-language pages will not slug-match (`/contacto/` ≠ `/contact/`), so the
engine leans on Tier B/C to propose them; an editor confirms in the review queue
or CSV. To bootstrap immediately, hand-seed the obvious legal/contact pairs. A
minimal review CSV (`decision=confirm` on the rows you trust):

```csv
group_uuid,decision,status,market,language,hreflang,url,title,is_x_default,matched_by,confidence,valid,translated_title,translated_slug,notes
g-contact,confirm,proposed,global,en,en,https://pro.boehringer-ingelheim.com/contact/,Contact,yes,manual,1,1,,,
g-contact,confirm,proposed,es,es,es,https://www.cardiorrenal.es/contacto/,Contacto,,manual,1,1,,,seed
g-privacy,confirm,proposed,global,en,en,https://pro.boehringer-ingelheim.com/privacy-policy/,Privacy Policy,yes,manual,1,1,,,
g-privacy,confirm,proposed,es,es,es,https://www.cardiorrenal.es/politicadeprivacidad/,Política de privacidad,,manual,1,1,,,seed
g-cookies,confirm,proposed,global,en,en,https://pro.boehringer-ingelheim.com/cookies/,Cookies,yes,manual,1,1,,,
g-cookies,confirm,proposed,es,es,es,https://www.cardiorrenal.es/cookies/,Cookies,,manual,1,1,,,seed
```

Upload it on the hub's CSV review screen. Only `confirmed`, `valid` rows go live;
each row's target is re-validated (200 / canonical / indexable) before it serves.

## 4. What maps - and what deliberately does not

`cardiorrenal.es` is a therapy-area microsite, not a full mirror of the corporate
market, so **most** corporate pages have no Spanish equivalent - and that is
correct. Only genuine 1:1 equivalents should share a group:

| Corporate (Global) | cardiorrenal.es | Outcome |
|--------------------|-----------------|---------|
| `/contact/`        | `/contacto/`    | mapped  |
| `/privacy-policy/` | `/politicadeprivacidad/` | mapped |
| `/cookies/`        | `/cookies/`     | mapped  |
| a corporate press page | *(none)*    | no `es` alternate (safe) |
| gated `/medicina/` HCP area | *(never a target)* | excluded - not public/indexable |

Pages without a confirmed equivalent simply carry no Spanish alternate; the
country/language switcher then falls back per its configured `unresolved` policy
(keep the market home, hide the entry, or a section-root link).

## 5. Governance reminders (pharma)

- **Only public, indexable, HTTP-200 targets** are emitted (rule 5). Login-gated
  areas (`/medicina/`, `/enfermeria/`) are never hreflang targets.
- Cross-linking a therapy-area portal with a corporate site is a **content
  classification** decision - the human review step is the control. The AI only
  proposes; nothing cross-brand goes live without an editor's confirm.
- AI matching sends **metadata only** (title/slug) to the provider by default.
