# SETUP - Installing hrefl for WordPress (beginner’s guide)

Gets the **Hreflang Cross-Site** plugin installed and running across your
WordPress sites. Written for someone new to it; each step is explained. When
you’re done, the plugin links the matching pages across your sites so search
engines and visitors always find the right language/country version.

> This is for a family of **WordPress** sites (site A, site B, … all WordPress).
> It does not connect to Drupal sites - that’s intentional.

---

## 0. Before you begin

- [ ] Two or more **WordPress 6.2+** sites (your country sites), PHP **8.0+**.
- [ ] Admin access to each site (**Plugins** and **Settings** screens).
- [ ] Ability to edit **wp-config.php** on each site (for the shared password) -
      your hosting panel or developer can do this.
- [ ] **WP-Cron** working (it does by default; high-traffic sites often use a
      real server cron - ask your host).

**Which site does what?**

- The plugin is installed on **every** site - it is one plugin, not two. What a
  site *does* is decided by the **Role** setting, not by what you install.
- On **one** site (your main one) you set the role to **“Client + Hub”** - that
  site also stores the mappings and exposes the API.
- On every other site you set the role to **“Client only”**.

The Role dropdown has a third option, **“Hub only”**. Use it only if the site
running the hub is *not itself* one of your country sites - for example a
separate coordination site that should never emit hreflang tags of its own. If
your main site is also a market (the usual case), you want **“Client + Hub”**.

> **All your sites must be WordPress.** This plugin only talks to other
> WordPress sites running the same plugin. There is a separate Drupal module,
> but the two are **not** interoperable - you cannot have the hub on WordPress
> and a client on Drupal. A mixed family needs one family per platform, each
> with its own hub.

---

## 1. Install the plugin (on every site)

1. In wp-admin, go to **Plugins → Add New → Upload Plugin**.
2. Choose `hrefl-wordpress.zip` and click **Install Now**.
3. Click **Activate**.

Repeat on each site in the family.

> No command line needed. (If you prefer, you can instead copy the `hrefl-wp/`
> folder into `wp-content/plugins/` and activate it from the Plugins screen.)

---

## 2. Set the shared password (on every site)

The sites prove their identity to each other with one shared password. Add the
**same** line to **wp-config.php** on the hub site and on every client site -
above the `/* That's all, stop editing! */` comment:

```php
define('HREFL_HUB_SECRET', 'change-me-to-a-long-random-string');
```

- Use a long, random value. Same value everywhere.
- If you can’t edit wp-config.php, you can instead paste the secret into the
  **Shared secret** field on the plugin’s settings page - but the wp-config
  constant is safer.

---

## 3. Configure the hub (your main site)

Open **Hreflang** in the admin menu, then set:

- **Role** → **Client + Hub**
- **This market key** → a short code for this site, e.g. `global`
- **Markets** → one line per site, `market|prefix`. A prefix is that site’s web
  address (a path or a whole domain). Example:
  ```
  global|https://main.example/
  de|https://de.example/
  es|https://es.example/
  ```
- **Language map** → e.g. `en|en-US`

Click **Save Changes**.

---

## 4. Configure each other site (the clients)

On every non-hub site, open **Hreflang** and set:

- **Role** → **Client only**
- **This market key** → this site’s code (e.g. `de`)
- **Hub REST URL** → your main site + `/wp-json/hrefl/v1`, e.g.
  `https://main.example/wp-json/hrefl/v1`
- **Language map** → e.g. `de|de`

Click **Save Changes**.

Leave **Emit hreflang head tags** and **Serve sitemap** on.

---

## 5. Let it sync, then review

The sites talk to each other on WP-Cron (about hourly). To see it sooner you can
just browse the sites a few times (that triggers WP-Cron), then:

1. On the **hub** site, open **Hreflang → Review queue**.
2. You’ll see proposed links between matching pages.
3. Click **Confirm** on the ones that are correct (or **Reject**).
   - A link can only be confirmed once its target page has been checked
     (reachable + indexable) - that check also runs on cron, so if a new row
     can’t be confirmed yet, try again a little later.
4. Confirmed links go live on each site at the next sync.

---

## 6. Check it worked

- View the page source of a page that has a confirmed mapping - you should see
  `<link rel="alternate" hreflang="…">` tags in the `<head>`.
- Open `https://<your-site>/hrefl-sitemap.xml` - a multilingual sitemap.
- Add the selector anywhere with the shortcode: `[hrefl_selector]`.

> If the sitemap gives a 404, go to **Settings → Permalinks** and click **Save**
> once (this refreshes WordPress’s URL rules).

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Nothing in the Review queue | Sites haven’t synced yet - browse them a few times or wait for cron; re-check the client’s **Hub REST URL**, **market**, and secret. |
| “Cannot confirm: target not validated” | The target check hasn’t run yet or the page isn’t reachable/indexable - wait for the validation cron, or fix/publish that page. |
| Client can’t reach the hub | Check the **Hub REST URL** and that the **HREFL_HUB_SECRET** matches on both sides. |
| Sitemap 404 at `/hrefl-sitemap.xml` | Settings → Permalinks → Save; make sure **Serve sitemap** is on. |
| Links don’t appear on pages | Make sure **Emit hreflang head tags** is on and the mapping is **confirmed**. |

---

## What’s included (and what isn’t, yet)

**Included:** cross-site hreflang tags, multilingual sitemap, country/language
selector, signed site-to-site sync, URL-ownership, SSRF-safe target validation,
automatic same-slug matching, and the review queue.

**Not yet (planned):** AI-assisted matching/translation, CSV import/export,
bulk review, and a health dashboard. See `README.md` for the full parity list.
