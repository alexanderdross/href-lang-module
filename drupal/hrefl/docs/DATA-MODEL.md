# Data Model, Hub API & CSV Schema

Concrete shapes for the translation-group registry, the hub HTTP contract, and
the CSV review format. Platform-neutral on purpose (see `docs/ARCHITECTURE.md`
§9).

## 1. Core concept: the translation group

A **translation group** is one set of URLs across markets that represent the
same content. Everything hangs off it. Reciprocity is guaranteed because all
members share one group.

```
TranslationGroup
  group_uuid        UUID          stable id for the set
  x_default_member  ref|null      which member is x-default (usually Global en)
  created, updated  timestamps

GroupMember  (many per group)
  group_uuid        UUID          FK
  market            string        "global" | "de" | "us" | "ca"
  language          string        ISO 639-1  ("en","de","fr")
  hreflang          string        emitted code ("en","de","en-US","fr-CA")
  url               string        ABSOLUTE, canonical, https
  entity_type/id    string/int    Drupal entity on the owning backend (nullable)
  status            enum          proposed | confirmed | rejected | held
  matched_by        enum          key | schema | glossary | embedding | llm | manual
  confidence        float|null    0..1 fused score
  signals           json          which signals fired + sub-scores (explainable)
  asserted_by       string        backend that claimed this URL (provenance)
  source_changed    timestamp     content 'changed' on the owning backend
  last_validated    timestamp     last 200/canonical/index check
  valid             bool          result of that check
  version           int           registry version (audit / rollback)
```

`matched_by` records the **strongest** signal; `signals` keeps the full,
explainable breakdown (e.g. `{ "glossary":0.6, "embedding":0.83, "llm":0.9 }`).
`held` is the low-confidence tier from `docs/AUTOMATION.md` §4.

Invariants enforced by the hub:

- Within a group, each `hreflang` code is unique.
- A group has 0 or 1 `x_default_member`.
- Only `status = confirmed` **and** `valid = true` members are served to
  clients / emitted.

## 2. URL inventory (what clients publish)

```
InventoryRecord
  market, language, hreflang     as above (backend-configured)
  url                            absolute canonical
  entity_type, entity_id
  title, meta_description        content signals for the AI matcher
  headings[], breadcrumb[]       (scope-gated; may be omitted for governance)
  body_excerpt                   optional, opt-in only
  changed                        timestamp
  within_site_group              id linking native translations (e.g. CA en/fr)
  indexable, canonical_url, http_status   for validation
```

## 3. Hub HTTP API (JSON over HTTPS)

All endpoints authenticated per backend (service account / signed request).

### `POST /hrefl-hub/api/v1/inventory`
Client → hub. Body: `{ market, published_at, records: InventoryRecord[] }`
(batched/paged). Hub upserts, flags deltas for matching, revalidates targets.
Response: `{ accepted, updated, flagged_for_match }`.

### `GET /hrefl-hub/api/v1/alternates?market=de&since=<ts>`
Client ← hub. Returns confirmed, valid, resolved alternates for that market:

```json
{
  "market": "de",
  "generated_at": "2026-07-15T12:00:00Z",
  "pages": [
    {
      "url": "https://.../de/ueber-uns",
      "alternates": [
        { "hreflang": "de",    "href": "https://.../de/ueber-uns" },
        { "hreflang": "en-US", "href": "https://.../us/about-us" },
        { "hreflang": "en-CA", "href": "https://.../ca/about-us" },
        { "hreflang": "fr-CA", "href": "https://.../ca/fr/a-propos" },
        { "hreflang": "en",       "href": "https://.../about-us" },
        { "hreflang": "x-default","href": "https://.../about-us" }
      ]
    }
  ]
}
```

The client stores this verbatim; head injection, the **own multilingual sitemap
generator**, **and the country/language dropdown** all read from it. `since` enables
incremental pulls. The dropdown component (or a decoupled front end) can also
read the current page's `alternates` array directly as its option list - each
entry already carries the target `hreflang` (market + language) and the
absolute `href` of the equivalent page.

### `GET /hrefl-hub/api/v1/export.csv` and `POST /hrefl-hub/api/v1/import.csv`
The review loop (§4). Export current mapping; import editor decisions.

## 4. CSV review schema

One row per group member. Editors sort/group by `group_uuid`. Round-trip safe:
export → edit → import re-attaches rows to groups by `group_uuid`.

| Column           | Meaning / editor action |
|------------------|-------------------------|
| `group_uuid`     | Group id. Blank in a new row = create a new group. Same value across rows = same group. |
| `decision`       | Editor sets: `confirm` \| `reject` \| `move:<group_uuid>` \| `leave`. |
| `status`         | Current status (read-only reference): proposed/confirmed/rejected. |
| `market`         | global \| de \| us \| ca |
| `language`       | ISO 639-1 |
| `hreflang`       | Emitted code (editable if the default is wrong). |
| `url`            | Absolute canonical URL. |
| `is_x_default`   | `yes` on exactly one row per group. |
| `matched_by`     | ai \| pattern \| manual (read-only). |
| `confidence`     | 0..1 for proposals (read-only). |
| `title`          | Page title, to help the reviewer judge (read-only). |
| `ai_title`       | AI-**translated** title for this member's language, when the equivalent was proposed by translation (editable; reviewer corrects). |
| `ai_slug`        | AI-**translated** URL slug used to locate/propose the equivalent page (editable; reviewer corrects). |
| `valid`          | Target passed 200/canonical/index check (read-only). |
| `notes`          | Free text; editor rationale, kept in audit log. |

Import rules:

- `confirm` → member becomes `confirmed` (only if `valid`).
- `reject` → member becomes `rejected`, never emitted.
- `move:<uuid>` → re-home the member to another group (fixing a bad AI cluster).
- New row with blank `group_uuid` → editor is manually adding an equivalence.
- `ai_title` / `ai_slug` carry AI **translation** proposals for review; the
  editor corrects them in-line. They are advisory (they help locate/propose the
  equivalent page and give the reviewer a localized check) - both the **mapping
  and its translations are human-reviewed before anything goes live**.
- Import validates the whole file against `docs/HREFLANG-RULES.md` before
  applying; a failing group is reported and skipped, not half-applied.

## 4a. Supporting stores (hub)

- **URL/slug glossary** - learned dictionary of equivalent path tokens per
  language pair (`about-us`↔`ueber-uns`↔`a-propos`), grown from editor
  corrections; feeds Tier-A deterministic matching next time.
- **Embedding store** - one vector per URL per content version (multilingual
  model, preferably self-hosted), with an ANN index for candidate search.
  Keyed by `(url, content_hash)` so re-embedding only happens on real change.
- **Feedback log** - every confirm/reject/regroup with signals + actor, used for
  threshold tuning and few-shot examples (`docs/AUTOMATION.md` §7).
- **Registry versioning** - each apply bumps `version`; supports diff, audit,
  and rollback of a bad bulk change.

## 5. Local client store

Each backend caches its `GET /alternates` payload in a small table keyed by URL,
plus Drupal cache. Rendering and sitemap generation read only this local store,
so the hub is never in the request path.

## 6. Example: the "About us" group

```
group_uuid: 7f3a…  x_default: Global/en
  global | en    | en     | https://.../about-us        | x-default + en
  de     | de    | de     | https://.../de/ueber-uns
  us     | en    | en-US  | https://.../us/about-us
  ca     | en    | en-CA  | https://.../ca/about-us      | native pair with ↓
  ca     | fr    | fr-CA  | https://.../ca/fr/a-propos   | native pair with ↑
```

Every one of these five URLs emits all six annotations (self + others +
x-default), in `<head>` and in the sitemap - reciprocal, absolute, valid.
