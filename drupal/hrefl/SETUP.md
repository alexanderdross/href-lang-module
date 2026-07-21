# SETUP - Installing hrefl (beginner’s guide)

This page gets the **hrefl** module *installed and running*. It assumes no prior
knowledge of the module and explains each command. When you’re done, follow
[`docs/SETUP-GUIDE.md`](docs/SETUP-GUIDE.md) to configure it.

> **You need command-line access to your Drupal sites** (or someone who has it -
> your developer or hosting team). Installing a Drupal module can’t be done from
> the browser alone, because the code has to be downloaded first. Once it’s
> installed, all the *configuration* is done by clicking (see the setup guide).

---

## 0. Before you begin - checklist

- [ ] One or more **Drupal 10.3 or 11** websites (your country sites).
- [ ] **Composer** available on each site (the PHP package manager).
- [ ] **Drush** available (the Drupal command-line tool). Optional but
      recommended - you can also click in the admin UI instead.
- [ ] Ability to set an **environment variable** on the server (for the shared
      password). Your hosting team can do this.

**Which site gets what?**

- **`hrefl_client`** → install on **every** country website.
- **`hrefl_hub`** → install on **one** website only (your main / Global site,
  usually the domain root). That site runs *both*.

> **All your sites must be Drupal.** This module only talks to other Drupal
> sites running the same module. There is a separate WordPress plugin, but the
> two are **not** interoperable - you cannot have the hub on Drupal and a client
> on WordPress. A mixed family needs one family per platform, each with its own
> hub.

---

## 1. Download the code (on each site)

Run this in the site’s project folder:

```bash
composer require boehringer-ingelheim/hrefl
```

`composer require` downloads the module and its dependencies into your project.
It does **not** turn anything on yet - that’s the next step.

> If your project isn’t managed by Composer, download the module folder into
> `web/modules/custom/hrefl` (or `modules/custom/hrefl`) manually. Composer is
> strongly recommended, though.

---

## 2. Turn on the modules

You can do this with Drush **or** by clicking in the admin area.

### Option A - Drush (fastest)

On **every** site:
```bash
drush en hrefl_client
```
On the **Global/main** site only, additionally:
```bash
drush en hrefl_hub
```

### Option B - Admin UI (no command line)

1. Go to **Extend** (`/admin/modules`).
2. Tick **“Hreflang Client”** and click **Install** - on every site.
3. On the Global site, also tick **“Hreflang Hub”** and install it.

> You’ll see an umbrella item called **“Hreflang Cross-Backend”** - it has no
> code of its own; you only need the two sub-modules above.

---

## 3. Run database updates

The module creates a few database tables and columns. Apply them:

```bash
drush updatedb        # or: drush updb
```

(Admin UI alternative: visit `/update.php` and follow the steps.)

Do this on every site after installing. It’s safe to run again later when you
update the module.

---

## 4. Create the shared password (“secret”)

The sites prove their identity to each other with one shared password. The
simplest way to start: set the **same** environment variable on the hub site and
on every client site.

```bash
HREFL_HUB_SECRET=change-me-to-a-long-random-string
```

- Ask your hosting team where to set environment variables (often a `.env` file,
  the hosting control panel, or the server config).
- Use a long, random value. You can generate one later on the hub’s **Markets**
  screen with the **“Generate a shared secret”** button.
- Same value everywhere. If the hub and a client don’t match, that client can’t
  connect.

> Production tip: instead of an environment variable, store the secret in the
> Drupal **Key** module and enter its key name in the settings. The env var is
> perfect for getting started and testing.

---

## 5. Clear the cache

```bash
drush cr        # or, in the UI: /admin/config/development/performance → Clear all caches
```

This makes Drupal pick up the new routes, services and admin pages.

---

## 6. Check it worked

1. On any site, open **Configuration → Search and metadata → Hreflang Client
   settings** (`/admin/config/search/hrefl`). If the page loads, the client is
   installed. ✅
2. On the Global site, open **Hreflang Hub** (`/admin/config/search/hrefl-hub`).
   You should see four tabs: **Settings, Markets, Review queue, Health**. ✅
3. (Optional, Drush) Print what a page would emit:
   ```bash
   drush hrefl:show https://your-site.example/some-page
   ```
   With nothing configured yet it will simply report “no stored alternates” -
   that’s expected at this point.

If all three work, installation is complete.

---

## 7. (Optional) AI keys

Only if you want the AI to help propose matches and translations. Set the key
for whichever provider you’ll use, on the **hub** site:

```bash
HREFL_COPILOT_KEY=...        # Microsoft Copilot
HREFL_ANTHROPIC_KEY=...      # Anthropic (pick one; both are supported)
```

The module works fine with **no AI** - you can skip this and add it later.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `composer require` can’t find the package | Check the package name and that your Composer repositories include it; your developer may need to add a repository entry. |
| “Module not found” on Extend | The code isn’t downloaded yet - do Step 1 first, then clear cache (Step 5). |
| `drush: command not found` | Use the admin UI (Option B / `/update.php`) instead, or ask your developer to install Drush. |
| Update errors after install | Re-run `drush updatedb`; make sure you ran it on the site where you enabled the module. |
| Admin pages give “Access denied” | Your user needs the permission *Administer Hreflang Client/Hub* - grant it at `/admin/people/permissions`. |
| Nothing happens between sites | That’s configuration, not installation - continue to the setup guide below. |

---

## Next step: configure it

Installation is done. Now connect and configure the sites - a mostly
point-and-click process - using the full guide:

**→ [`docs/SETUP-GUIDE.md`](docs/SETUP-GUIDE.md)**

That guide walks you through: pointing each client at the hub, adding your
markets (countries), setting language codes, and reviewing the proposed links.
