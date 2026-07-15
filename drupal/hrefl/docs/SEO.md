# SEO Best Practices

This is the broader SEO playbook for the module. The exact, enforced `hreflang`
correctness rules live in `docs/HREFLANG-RULES.md`; this doc covers the wider
practices the design commits to.

## 1. hreflang correctness (enforced - see HREFLANG-RULES.md)

Reciprocal / return tags, self-referencing, fully-qualified absolute URLs, valid
ISO 639-1 [+ 3166-1 alpha-2] codes, exactly one `x-default` per group, and
targets that are 200 / canonical / indexable only. Reciprocity is guaranteed by
the shared translation-group model; the rest is validated continuously.

## 2. Carrier strategy: sitemap-first at scale

Use the **XML sitemap** (`xhtml:link` alternates, emitted by the module's **own**
multilingual sitemap generator in `hrefl_client`) as the primary hreflang carrier
for the full family graph, keeping HTML `<head>` lean.
Optionally emit `<head>` tags for high-value sections for robustness. Whatever
mix is chosen, **both carriers are generated from the same store and must
match** - conflicting signals across methods is a common ranking-killer.

## 3. Canonical + hreflang consistency

- Every page is **self-canonical**; hreflang points only at canonical URLs.
- Never annotate a URL that canonicalizes elsewhere, is `noindex`, paginated
  duplicate, or a faceted/parameter variant.
- hreflang and canonical must not contradict each other (self-canonical + full
  hreflang set is the correct pattern).

## 4. x-default strategy

Designate one member per group as `x-default` - assumed the Global English page
(the audience-neutral fallback). Confirm with SEO whether Global emits `en` +
`x-default` or `x-default` only, and finalize the per-market code table in
`docs/HREFLANG-RULES.md`.

## 5. No automatic IP/geo redirects

Auto-redirecting by IP/locale harms international SEO: it can prevent Googlebot
(which largely crawls from the US) from seeing localized versions, wastes crawl
budget on redirect hops, traps users on the wrong version, and hurts Core Web
Vitals. **Excluded by design.** Instead: correct hreflang lets each localized
URL rank for its audience, and the **user-initiated selector** lets visitors
switch - the combination Google recommends.

## 6. Crawlable country/language selector

- Real, server-rendered `<a href>` links to the equivalent localized URLs
  (context-preserving), not JS-only handlers - so search engines discover and
  follow the cross-links, reinforcing the hreflang graph.
- Where a page has no equivalent in a target market, link to that market's
  section/home and mark it, rather than emitting a broken link.

## 7. Structured data (machine-readability / AI search)

Optionally strengthen signals with schema.org JSON-LD: `inLanguage` on each
page, and relationship properties (`translationOfWork` / `workTranslation`, or
`sameAs` to equivalents) to make the localized relationships explicit to search
engines and AI assistants. (See the `structured-data` skill for generating and
validating this markup.) Keep it consistent with the hreflang graph - same
source of truth.

## 8. Indexation hygiene

Only index-worthy, canonical pages enter groups and get alternates. Exclude
staging, search results, faceted/parameter URLs, and `noindex` content from both
the mapping and the sitemap.

## 9. Monitoring & validation

- **Automated pre-publish validation** against the rules checklist; block or
  warn on violations.
- **Google Search Console** International Targeting / coverage monitoring; watch
  for "no return tags", "unknown language code", and hreflang coverage drops.
- **Continuous drift/broken-target detection** by the Monitor; alert on any
  one-sided or dead alternate.
- Track hreflang **coverage %** and error counts as SEO KPIs alongside the
  automation KPIs in `docs/AUTOMATION.md`.

## 10. Common mistakes this design prevents

- Missing return tags → structurally impossible (shared group).
- Relative/protocol-relative URLs → always absolute.
- Wrong/duplicate codes, missing/duplicate x-default → validated per group.
- hreflang to redirects/404s/noindex → dropped by validation.
- Conflicting head vs sitemap → single source of truth.
- Auto-redirect masking localized URLs from crawlers → excluded by design.

## Sources
- [Localized versions of your pages - Google Search Central](https://developers.google.com/search/docs/specialty/international/localized-versions)
- [Multilingual SEO: frequent issues and how to fix them - Seobility](https://www.seobility.net/en/blog/multilingual-seo-issues/)
- [Hreflang at scale: automation tips - Hashmeta](https://hashmeta.com/blog/hreflang-at-scale-automation-tips-for-multi-lingual-seo-agencies/)
- [Multi-language & multi-region XML sitemap best practices - GtechMe](https://www.gtechme.com/insights/best-practices-for-multi-language-and-multi-region-xml-sitemaps-hreflang-support/)
