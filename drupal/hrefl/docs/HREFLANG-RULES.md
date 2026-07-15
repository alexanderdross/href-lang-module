# hreflang Correctness Rules

These are the rules the module MUST enforce on every emitted annotation, in the
`<head>`, in HTTP headers, and in the XML sitemap. They come from Google's
current specification for localized-version annotations. Getting any of these
wrong causes search engines to **ignore** the annotations - which is worse than
having none, because the SEO-dilution problem the module exists to solve returns
silently.

## 1. The three emission methods (we use two, optionally three)

1. **HTML `<link>` tags** in `<head>` - primary method for pages.
   `<link rel="alternate" hreflang="LANG" href="ABSOLUTE_URL">`
2. **XML sitemap `xhtml:link`** - via the module's **own** multilingual sitemap
   generator; each `<url>` lists all variants (plus `<lastmod>` / `<priority>`).
   Namespace `xmlns:xhtml="http://www.w3.org/1999/xhtml"`.
3. **HTTP `Link` header** - optional, for non-HTML files (PDFs):
   `Link: <URL>; rel="alternate"; hreflang="LANG", …`

The same resolved set feeds all three, so they always agree.

## 2. Absolute, fully-qualified URLs - mandatory

Every `href` must include the scheme and host:
`https://pro.boehringer-ingelheim.com/de/ueber-uns` - **never** `/de/ueber-uns`
and **never** protocol-relative `//host/de/ueber-uns`. The module always builds
absolute URLs from each backend's configured base URL.

## 3. Reciprocity / return tags - mandatory

If page X lists page Y as an alternate, page Y **must** list X back. Missing
return tags are the most common hreflang failure ("no return tags"). The module
guarantees this **by construction**: equivalence is stored as a single
*translation group* shared by all members, so every member emits the full
member list - the return link cannot be missing or out of sync.

## 4. Self-referencing - mandatory

Each page includes an alternate pointing at **itself** (its own market/language).
A group of N members emits N `<link>` entries on every member page (self + N-1
siblings), plus x-default.

## 5. Valid language/region codes

- Language = **ISO 639-1** (`en`, `de`, `fr`). Region = optional **ISO 3166-1
  alpha-2** (`US`, `DE`, `CA`), joined with a hyphen: `en-US`, `fr-CA`.
- Region alone is invalid; a bare made-up code (e.g. `be` meaning "Belgium") is
  invalid - it must be `language-REGION`.
- Case is not significant to Google but the module normalizes to
  `lower-UPPER` (`en-US`) for consistency and clean diffs.

### 5.1 Draft market → hreflang code mapping (CONFIRM WITH SEO)

| Backend / path | Language | Proposed `hreflang` | Notes |
|----------------|----------|---------------------|-------|
| `/` Global     | en       | `en` + `x-default`  | Global English; also the x-default target (see §6) |
| `/de/`         | de       | `de`                | Confirm `de` vs `de-DE` |
| `/us/`         | en (US)  | `en-US`             | Region-specific English |
| `/ca/`         | en (CA)  | `en-CA`             | Native within Canada backend |
| `/ca/fr/`      | fr (CA)  | `fr-CA`             | Native within Canada backend |

This table is a **draft** and an open TA question (see `CLAUDE.md` §8). Whether
Global emits `en` and `x-default` both, or `x-default` only, and whether Germany
is `de` or `de-DE`, must be signed off by SEO. The code per backend is
configuration, not hard-coded.

## 6. Exactly one `x-default` per group

`x-default` is the fallback for users whose language/region matches no variant.
Each translation group designates **one** member as `x-default` (assumed: the
Global English page). The module validates that a group has zero-or-one
x-default (never two) and warns if a group large enough to warrant one has none.

## 7. Only clean, indexable targets

An alternate may point **only** at a URL that is:

- HTTP **200** (not 3xx/4xx/5xx),
- **canonical** (not a URL that canonicalizes elsewhere),
- **indexable** (not `noindex`).

Ingest-time and periodic revalidation drop any membership whose target fails
these checks, so the module never advertises a broken or non-indexable variant.
`hreflang` and `rel=canonical` must be consistent - never point hreflang at a
non-canonical URL.

## 8. Consistency of URL form

Pick and keep one canonical form per URL (trailing slash, case, no tracking
params). The published alternate must exactly match the target's own
self-referencing/canonical URL, or reciprocity checks fail on a technicality.

## 9. Safe degradation

If a page has no `confirmed` cross-market group, the module emits **only** the
safe subset - the self link and any native within-site translations Drupal
already knows (e.g. Canada en/fr) - and nothing speculative. An unconfirmed AI
proposal is never emitted to visitors or search engines.

## 10. Validation checklist (module self-checks)

- [ ] All hrefs absolute + `https`.
- [ ] Every member lists every other member + itself (reciprocal, complete).
- [ ] Each hreflang code valid (639-1 [+ 3166-1]) and unique within the group.
- [ ] ≤ 1 `x-default` per group.
- [ ] No target is 3xx/4xx/5xx/noindex/non-canonical.
- [ ] Head tags, HTTP headers and sitemap emit the identical set.
- [ ] Unmatched pages fall back to the safe subset only.

## Sources

- [Localized versions of your pages - Google Search Central](https://developers.google.com/search/docs/specialty/international/localized-versions)
- [Multilingual and multinational site annotations in Sitemaps - Google Search Central blog (2012, the reference cited in WFACQUIA-81)](https://developers.google.com/search/blog/2012/05/multilingual-and-multinational-site)
