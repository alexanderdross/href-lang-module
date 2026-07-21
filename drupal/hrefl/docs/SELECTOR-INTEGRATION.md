# Wiring an existing country/language switcher to hrefl

You already have a country/language switcher (a dropdown, a slide-in panel, or a
tabbed "All / Europe / Asia / ..." section). You do **not** have to replace it
with the module's own selector block. Instead, keep your UI and let hrefl supply
the **per-page equivalent URL** for each entry, so choosing a market on
`/imprint/` lands on `/de/impressum/` - not on the German home page.

This is the headless path: the `hrefl_client/selector_adapter` library reads the
per-URL feed and rewrites the `href` of each annotated link where a **confirmed**
equivalent exists. Links without one keep their existing (market-home) href, so
the switcher never breaks or guesses (correctness rule 6). If the feed is down,
every link keeps its static href.

## 1. How the feed works

```
GET /hrefl/selector?url=<absolute-url-of-the-current-page>
```

```json
{
  "url": "https://pro.boehringer-ingelheim.com/imprint/",
  "alternates": [
    { "hreflang": "en",    "label": "English", "href": "https://pro.boehringer-ingelheim.com/imprint/" },
    { "hreflang": "de",    "label": "Deutsch", "href": "https://pro.boehringer-ingelheim.com/de/impressum/" },
    { "hreflang": "fr-CA", "label": "Français (Canada)", "href": "https://pro.boehringer-ingelheim.com/ca/fr/mentions-legales/" }
  ]
}
```

The feed reads only the local store - **no cross-backend call at request time** -
and is edge-cacheable per URL (`Cache-Control: public, max-age=300`).

## 2. Annotate your switcher links

Tag each country/language link with the hreflang it targets. Two ways:

**a) By hreflang, directly (simplest):**

```html
<a data-hrefl-hreflang="de" href="/de/">Germany &ndash; Deutsch</a>
<a data-hrefl-hreflang="en" href="/">Global &ndash; English</a>
```

**b) By market, with a map** - useful when your markup already carries a market
key (e.g. `pro.` organises by country):

```html
<a data-hrefl-market="de" href="/de/">Germany</a>
<a data-hrefl-market="us" href="/us/">United States</a>
```

```js
drupalSettings.hreflClientSelector.markets = { de: 'de', us: 'en-US' };
```

An explicit `data-hrefl-hreflang` always wins over `data-hrefl-market`.

## 3. Attach the library + settings

Attach `hrefl_client/selector_adapter` and pass the current page's absolute URL
(and, if you use market keys, the market->hreflang map). From a custom module or
your theme:

```php
/**
 * Implements hook_page_attachments().
 */
function mytheme_page_attachments(array &$attachments): void {
  // Only where your switcher actually renders; here: everywhere.
  $attachments['#attached']['library'][] = 'hrefl_client/selector_adapter';
  $attachments['#attached']['drupalSettings']['hreflClientSelector'] = [
    // The canonical absolute URL of the current page (what the feed keys on).
    'currentUrl' => \Drupal\Core\Url::fromRoute('<current>', [], ['absolute' => TRUE])->toString(),
    // Optional: only if you annotate links by market instead of hreflang.
    'markets' => ['de' => 'de', 'us' => 'en-US', 'ca' => 'en-CA'],
    // Optional overrides (defaults shown):
    // 'feedBase'     => '/hrefl/selector',
    // 'linkSelector' => '[data-hrefl-hreflang], [data-hrefl-market]',
  ];
}
```

`\Drupal\Core\Url::fromRoute('<current>', ...)` returns the current route's URL;
for entity pages prefer the entity's canonical URL so the feed key matches the
URL you published to the hub.

## 4. What the adapter does at runtime

For each annotated link, on page load:

| Situation | Result |
|-----------|--------|
| Confirmed equivalent exists for that hreflang | `href` upgraded to the equivalent page; `data-hrefl-resolved="equivalent"` added |
| No confirmed equivalent | link left as-is (its market-home href = safe fallback) |
| The equivalent is the current page | `aria-current="true"` added (mark it active) |
| Feed unreachable / error | all links left as-is |

Your markup, classes, and layout are untouched - only the `href`s change.

## 5. Prerequisites

- The `hrefl_client` submodule is installed on each market backend and has pulled
  its resolved alternates (cron, or the Phase 0 seed command).
- The equivalence (e.g. `/imprint/` <-> `/de/impressum/`) is **confirmed** in the
  hub - via auto-mapping + human review, CSV, or a hand-authored seed. Until it
  is, that entry safely falls back to the market home.

## 6. Topology notes

- **Path-prefix family (`pro.` : `/de/`, `/us/`, `/ca/fr/`)** - the module's
  native topology; annotate links by market or hreflang and you are done.
- **Separate regional domains (`www.` region panel)** - supported via the
  multi-domain market registry (own-domain markets), but each regional site must
  run `hrefl_client` and publish its inventory to the hub. The feed and this
  adapter work identically; the alternates just carry absolute cross-domain URLs.

## 7. Decoupled / non-Drupal front ends

The same feed (`GET /hrefl/selector?url=...`) backs a headless front end - fetch
it from React/Vue and render your own switcher. The adapter above is just a thin,
framework-free reference implementation of that fetch-and-rewrite step.
