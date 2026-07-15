# hrefl — cross-site hreflang

[![CI](https://github.com/alexanderdross/href-lang-module/actions/workflows/ci.yml/badge.svg)](https://github.com/alexanderdross/href-lang-module/actions/workflows/ci.yml)

Cross-site `hreflang` for a family of independent country sites: a **client** on
every site and a **hub** on one, connected by a signed API. It emits reciprocal
`hreflang` tags, a multilingual XML sitemap, and a country/language selector.

Two implementations of the same architecture live here, each in its own folder:

| Folder | Platform | What it is |
|--------|----------|------------|
| [`drupal/hrefl`](drupal/hrefl) | Drupal 10.3 / 11 | Two submodules (`hrefl_client`, `hrefl_hub`) — the full engine: tiered matching (deterministic → embeddings → AI), review queue, CSV round-trip, health dashboard. |
| [`wordpress/hrefl-wp`](wordpress/hrefl-wp) | WordPress 6.2+ / PHP 8 | A single self-contained plugin (client + hub by role) — signed REST sync, URL ownership, SSRF-safe validation, slug matching, review queue. |

The two are **independent** — an all-Drupal family uses the Drupal module, an
all-WordPress family uses the plugin. They are not meant to interoperate.

## Install & configure

- **Drupal:** see [`drupal/hrefl/SETUP.md`](drupal/hrefl/SETUP.md) and
  [`drupal/hrefl/docs/`](drupal/hrefl/docs).
- **WordPress:** see [`wordpress/hrefl-wp/SETUP.md`](wordpress/hrefl-wp/SETUP.md)
  and [`wordpress/hrefl-wp/README.md`](wordpress/hrefl-wp/README.md).

## Continuous integration

Every pull request runs [`.github/workflows/ci.yml`](.github/workflows/ci.yml):

- **standards** — PHP syntax lint + `phpcs` (Drupal + DrupalPractice) on both.
- **wordpress** — the plugin's standalone PHPUnit unit suite.
- **drupal** — the module's PHPUnit tests (unit + kernel) on a fresh Drupal 11.

These checks must pass before a PR can be merged.

## Related

Marketing website (Next.js, Cloudflare-hosted): https://github.com/alexanderdross/hrefl

## License

GPL-2.0-or-later.
