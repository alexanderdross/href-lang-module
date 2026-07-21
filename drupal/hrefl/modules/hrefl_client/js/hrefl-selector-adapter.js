/**
 * @file
 * hrefl selector adapter.
 *
 * Upgrades an existing country/language switcher so each entry links to the
 * *equivalent* page in that language (context-preserving) instead of the market
 * home. It reads the module's per-URL feed (`/hrefl/selector`) and rewrites the
 * href of any annotated switcher link for which a confirmed alternate exists.
 *
 * Links without a confirmed equivalent are left untouched: their existing href
 * (the market home) is the safe fallback - the adapter only ever *upgrades* a
 * link, it never guesses or breaks one (correctness rule 6). If the feed is
 * unreachable, every link keeps its static href.
 *
 * Annotate each switcher link with the hreflang it targets, either directly:
 *   <a data-hrefl-hreflang="de" href="/de/">Germany - Deutsch</a>
 * or by market, with a market->hreflang map in drupalSettings:
 *   <a data-hrefl-market="de" href="/de/">Germany</a>
 *   drupalSettings.hreflClientSelector.markets = { de: 'de', us: 'en-US' };
 *
 * See docs/SELECTOR-INTEGRATION.md for the full wiring example.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  Drupal.behaviors.hreflSelectorAdapter = {
    attach(context) {
      const settings = drupalSettings.hreflClientSelector || {};
      const feedBase = settings.feedBase || '/hrefl/selector';
      const linkSelector =
        settings.linkSelector || '[data-hrefl-hreflang], [data-hrefl-market]';
      const markets = settings.markets || {};
      const currentUrl =
        settings.currentUrl || (window.location && window.location.href);

      // Bind each annotated switcher link exactly once.
      const links = once('hrefl-selector', linkSelector, context);
      if (!links.length || !currentUrl) {
        return;
      }

      // Resolve the hreflang a given link targets: an explicit data-hrefl-hreflang
      // wins; otherwise map its data-hrefl-market through the configured table.
      const codeFor = (el) => {
        const direct = el.getAttribute('data-hrefl-hreflang');
        if (direct) {
          return direct.toLowerCase();
        }
        const market = el.getAttribute('data-hrefl-market');
        return market && markets[market] ? String(markets[market]).toLowerCase() : null;
      };

      // One feed request per page; upgrade every matching link when it resolves.
      fetch(`${feedBase}?url=${encodeURIComponent(currentUrl)}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
          if (!data || !Array.isArray(data.alternates)) {
            // Feed unavailable / malformed: keep the static market-home links.
            return;
          }
          const byCode = {};
          data.alternates.forEach((alt) => {
            if (alt && alt.hreflang && alt.href) {
              byCode[String(alt.hreflang).toLowerCase()] = alt;
            }
          });

          links.forEach((el) => {
            const code = codeFor(el);
            if (!code) {
              return;
            }
            const alt = byCode[code];
            if (!alt) {
              // No confirmed equivalent for this market: leave the existing
              // (market-home) link as the safe fallback.
              return;
            }
            // Upgrade to the equivalent page (context-preserving switch).
            el.setAttribute('href', alt.href);
            el.setAttribute('data-hrefl-resolved', 'equivalent');
            // Flag the current page's own language as active, if it matches.
            if (alt.href === currentUrl) {
              el.setAttribute('aria-current', 'true');
            }
          });
        })
        .catch(() => {
          // Network error: keep the static links (safe degradation).
        });
    },
  };
})(Drupal, drupalSettings, once);
