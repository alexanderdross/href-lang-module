# hrefl — Setup & Configuration Guide (for everyone)

This guide explains, in plain language, how to set up and run the **hrefl**
module. It is written so that a non‑technical person can do the day‑to‑day
configuration through the admin screens. A few one‑time steps need a developer
or your hosting team — those are clearly marked with **🛠️ Developer step**.

If you only ever do one thing here, it is **reviewing proposed links** (Part E).
Everything else is set up once and then mostly runs by itself.

---

## 1. What this module does (in one minute)

Your brand runs several separate websites for different countries — for example:

- `pro.boehringer-ingelheim.com/` (Global, English)
- `pro.boehringer-ingelheim.com/de/` (Germany)
- `pro.boehringer-ingelheim.com/us/` (USA)
- `pro.boehringer-ingelheim.es/` (Spain, its **own domain**)

Each of these is a **separate website** and normally has no idea the others
exist. hrefl connects the **matching pages** across them — e.g. the German
“Über uns” page is linked to the English “About us” page — so that:

1. **Search engines** (Google) understand these are the same page in different
   languages and don’t treat them as duplicates. This protects your rankings.
2. **Visitors** can switch country/language and land on the *same* page in the
   new market, not on the homepage.

It does this by adding invisible “**hreflang**” links to your pages, feeding a
**multilingual XML sitemap**, and powering a **country/language dropdown**.

### The two building blocks

hrefl comes as **two sub‑modules**:

| Nickname | Module name | Where it goes | What it does |
|---|---|---|---|
| **Client / child** | `hrefl_client` | on **every** country website | reports its pages, receives the links, shows them on pages + sitemap + dropdown |
| **Hub / master** | `hrefl_hub` | on **one** website only (usually the Global/root site) | stores all the links, proposes matches, lets you review them, hands each site its links |

The Global/root site runs **both** (it is a normal country site *and* the hub).

---

## 2. Who does what

| Task | Who | How often |
|---|---|---|
| Install the modules, set the shared password, (optional) AI keys | **🛠️ Developer** | once |
| Configure the hub + list your markets | Admin (mostly clicking) | once, then rarely |
| Configure each country site | Admin (mostly clicking) | once per site |
| **Review and confirm proposed links** | Editor / SEO | ongoing |
| Add a new country later | Admin | when a market launches |

An editor who only reviews links just needs the permission
**“Hreflang Hub: review mappings”** (a developer assigns this to their role).

---

## 3. Part A — One‑time technical setup (🛠️ Developer)

> A non‑technical person cannot do this part — it needs server/command access.
> Hand this section to your developer or hosting team. It’s quick.

1. **Install the package** on each website that participates:
   ```
   composer require boehringer-ingelheim/hrefl
   drush en hrefl_client      # on EVERY country site
   drush en hrefl_hub         # on the GLOBAL/root site only
   ```
2. **Create one shared password (“secret”)** that the sites use to trust each
   other. The simplest way for a start is an environment variable that is set to
   the **same value** on the hub and on every client:
   ```
   HREFL_HUB_SECRET=<a long random string>
   ```
   (In production, use the Drupal **Key** module instead — see Part F. You can
   generate a good random value from the **Markets** screen, see §5.3.)
3. **(Optional) AI keys**, only if you want the AI to help propose matches and
   translations. Set the key for whichever provider you use:
   ```
   HREFL_COPILOT_KEY=...        # Microsoft Copilot
   HREFL_ANTHROPIC_KEY=...      # Anthropic
   HREFL_EMBEDDING_KEY=...      # optional embeddings server (if it needs a key)
   ```
   The module works **without any AI** — AI only reduces manual work.
4. **Give roles the right permissions** at `/admin/people/permissions`:
   - Site admins: *Administer Hreflang Client* and *Administer Hreflang Hub*.
   - Editors/SEO: *Hreflang Hub: review mappings*.

That’s the whole technical part. Everything below is done by clicking in the
admin area.

---

## 4. Part B — Configure the Hub (the master site)

Go to the Global/root site and open **Configuration → Search and metadata →
Hreflang Hub** (`/admin/config/search/hrefl-hub`). It has four tabs:
**Settings**, **Markets**, **Review queue**, **Health**.

### 4.1 Settings tab

- **Canonical host** — the main web address of the family,
  e.g. `https://pro.boehringer-ingelheim.com`. (Used as a fallback when a market
  has no explicit address.)
- **Enable auto‑confirm** — leave this **off** at the start. Off means *every*
  proposed link waits for a human to approve it (safest). Turn it on later, once
  you trust the suggestions.
- **Auto‑confirm threshold / Floor threshold** — you can ignore these at first.
  They control how confident the AI must be to auto‑approve or to “hold” a weak
  guess. Defaults are fine.
- **AI matcher (Tier C)** — optional. Pick **Microsoft Copilot** *or*
  **Anthropic** (both are fully supported), then fill in that provider’s
  **endpoint** and **model**. A green “✔ Ready” note appears when the endpoint,
  model and key are all set. Leave **Data scope** on **“Metadata only”** — this
  keeps page *content* private and only sends titles/headings.
- **Semantic matching (Tier B embeddings)** — optional, advanced. If you have a
  self‑hosted embeddings server, put its address in the **HTTP embedding
  endpoint** box. If you don’t, leave it empty — matching still works.

Click **Save configuration**.

### 4.2 Markets tab — tell the hub about your countries

Open the **Markets** tab (`/admin/config/search/hrefl-hub/markets`). This is the
most important setup screen. Each “market” is one country website and the web
address it **owns**.

For each market you set:

- **Owned URL prefix** — the absolute web address this site lives at. Two shapes
  are supported and you can mix them:
  - a **path** under the shared host: `https://pro.boehringer-ingelheim.com/de/`
  - a **whole separate domain**: `https://pro.boehringer-ingelheim.es/`
- **HMAC secret key name** — leave empty if you’re using the simple
  `HREFL_HUB_SECRET` environment variable from Part A. (Advanced: the name of a
  Key‑module key holding a per‑market secret.)

To **add a market**: fill in the “Add a market” box (market key like `es`, the
prefix, optional key name) and **Save**. The market key is a short lowercase
code you choose (`de`, `us`, `es`, `fr`, `global`).

The **✔ / ⚠ Secret** note next to each market tells you whether a shared
password is currently found for it. ⚠ means the sites can’t authenticate yet —
fix the secret (Part A step 2).

Need a strong random password? Click **“Generate a shared secret”** — it shows
one you can copy. (It is shown once and not saved, on purpose.)

---

## 5. Part C — Configure each country site (the clients)

Do this **on every country website** (including Global). Open **Configuration →
Search and metadata → Hreflang Client settings** (`/admin/config/search/hrefl`).

- **Hub base URL** — the address of the hub’s API, which is the master site plus
  `/hrefl-hub/api/v1`, e.g.
  `https://pro.boehringer-ingelheim.com/hrefl-hub/api/v1`.
- **This market key** — the same short code you used on the Markets screen for
  *this* site (`de` on the German site, `es` on the Spanish site, `global` on
  the Global site).
- **Hub HMAC secret (key module key name)** — leave empty if you use the
  `HREFL_HUB_SECRET` environment variable; otherwise the Key‑module key name.
  This must correspond to the **same** secret configured on the hub for this
  market.
- **This backend base URL** — this site’s own web address,
  e.g. `https://pro.boehringer-ingelheim.com` (or `https://…es` for Spain).
- **Emit hreflang head tags** — leave **on**. Adds the hreflang links into each
  page.
- **Serve the multilingual sitemap** — leave **on**. Publishes the sitemap at
  `https://<this site>/hrefl/sitemap.xml`.
- **Default sitemap priority** — leave at `0.5` unless SEO tells you otherwise.
- **Emit hreflang HTTP Link header on non‑HTML responses** — turn **on** only if
  you have PDFs (or similar) that also need hreflang.
- **Thumbnail image field** — optional. If your pages have an image field (e.g.
  `field_image`), enter its machine name so reviewers see a thumbnail. Leave
  empty otherwise.
- **Langcode to hreflang map** — one line per language, `langcode|hreflang`.
  Examples:
  ```
  en|en-US
  de|de
  fr|fr-CA
  ```
  This turns Drupal’s internal language code into the exact hreflang code SEO
  wants. If unsure, ask your SEO team for the correct codes per market.

Click **Save configuration**, then repeat on the next site.

### 5.1 Add the country/language dropdown (optional but recommended)

At `/admin/structure/block`, place the block **“Country / language selector
(hreflang)”** (category *SEO*) in a region such as the header. It automatically
shows links to the equivalent page in every other market. Nothing to configure.

A machine‑readable version of the same data is available at
`https://<this site>/hrefl/selector` if a separate front end needs it.

---

## 6. Part D — How it works day to day

Once configured, the cycle runs mostly on its own (on Drupal **cron**):

1. Each country site **reports its pages** to the hub.
2. The hub **proposes matches** — first by simple rules (same/known page
   names), then, if enabled, with AI.
3. Proposed links appear in the **Review queue** as “proposed”.
4. **You review and confirm** them (Part E). Only confirmed links go live.
5. Each site **pulls its confirmed links** and shows them in page tags, the
   sitemap, and the dropdown.

Nothing unconfirmed is ever shown to visitors or search engines, and a page is
only linked if its target really works (is reachable and indexable).

---

## 7. Part E — Reviewing proposed links (the editor’s main job)

This is the part a non‑technical editor does regularly. Open **Hreflang Hub →
Review queue** (`/admin/config/search/hrefl-hub/review`).

You’ll see a table of proposed links. Each row shows:

- the **market**, the **hreflang** code, and the **URL**;
- a **Preview**: thumbnail (if available), page **title**, and any
  **AI‑suggested translation** (title / address);
- the **status**, a **confidence** score, and whether the **target is valid**.

To act on them:

1. **Tick the checkboxes** of the rows you want (or the header checkbox for all).
2. Click **“Confirm selected”** (approve) or **“Reject selected”** (discard).
3. A **confirmation page** appears — “Confirm N mappings?” — click to proceed.
4. A progress bar runs and you get a summary: how many were **confirmed**,
   **rejected**, or **skipped**.

“**Skipped**” means a link could not be confirmed because its target page isn’t
reachable/indexable yet, or two links would clash. That’s the safety net doing
its job — fix the page (or the mapping) and try again later.

You can also confirm/reject a single row with the **Quick** links, and
**Export CSV** to review offline in Excel.

> **CSV note:** downloading the CSV is one click. Re‑importing an edited CSV is
> currently a technical step (an API upload), so for everyday work the on‑screen
> **Review queue** is the recommended, fully non‑technical path. Ask your
> developer if you specifically need the offline CSV round‑trip enabled with an
> upload button.

---

## 8. Part F — Adding a new market later (onboarding)

When a new country launches (say France on its own domain):

1. **Markets tab** on the hub → “Add a market”: key `fr`, prefix
   `https://pro.boehringer-ingelheim.fr/` (or `…com/fr/` for a path) → Save.
2. Click **“Generate a shared secret”** and give it to your developer to store
   (Key module) or set as the shared secret; enter the key name if you use one.
3. 🛠️ Developer installs `hrefl_client` on the new site and sets the **same**
   secret and the **market key** `fr` in its Client settings (Part C).
4. Within a sync cycle the new site’s pages appear as **proposed** links in the
   Review queue — confirm them, and France is live.

Because ownership is by address prefix, both a **path** market (`…com/fr/`) and
a **separate‑domain** market (`…fr`) work exactly the same way.

---

## 9. Part G — Health & troubleshooting

Open **Hreflang Hub → Health** (`/admin/config/search/hrefl-hub/dashboard`) to
see:

- **Coverage** — how much of your content is confirmed and live.
- **Issue lists** — pages with a broken target, duplicate language codes,
  groups missing an `x‑default`, or a confirmed page with nothing to link to.

Common questions:

| Symptom | Likely cause | Fix |
|---|---|---|
| Nothing appears in the Review queue | Sites haven’t synced yet, or client not configured | Wait for cron; check the Client settings (hub URL, market, secret) |
| Market shows **⚠** on the Markets screen | Shared secret not found | Set `HREFL_HUB_SECRET` (same value on hub + client) or the Key‑module key |
| A link is always **skipped** on confirm | Target page is not reachable/indexable, or code clash | Fix/publish the target page; check the language codes |
| Links don’t show on the site | Head tags off, or nothing confirmed yet | Turn on “Emit hreflang head tags”; confirm links in the queue |
| Sitemap 404 at `/hrefl/sitemap.xml` | Sitemap disabled | Turn on “Serve the multilingual sitemap” in Client settings |

---

## 10. Glossary (plain language)

- **hreflang** — a small, invisible link that tells Google “this page is the
  same content, in this language, over there”.
- **Market** — one country website and the web address it owns.
- **Hub / client** — the master that stores all links vs. the copy on each site
  that shows them.
- **Mapping / translation group** — the set of pages across markets that are the
  same content. Confirming a mapping links them all, both ways.
- **x‑default** — the “if none of the languages fit, use this one” page (usually
  the Global English page).
- **Confirmed / proposed / held / rejected** — a link’s state. Only **confirmed**
  goes live.
- **Secret (HMAC)** — a shared password the sites use to trust each other’s
  messages. Same value on hub and client.
- **Tier A / B / C** — how a match is found: A = simple rules, B = meaning
  (embeddings), C = the AI decides on the tricky ones. A always runs; B and C are
  optional.

---

## 11. Quick reference

**Admin screens**

- Client settings: `/admin/config/search/hrefl`
- Hub settings: `/admin/config/search/hrefl-hub`
- Markets: `/admin/config/search/hrefl-hub/markets`
- Review queue: `/admin/config/search/hrefl-hub/review`
- Health: `/admin/config/search/hrefl-hub/dashboard`

**Public URLs (per site)**

- Sitemap: `https://<site>/hrefl/sitemap.xml`
- Selector data (JSON): `https://<site>/hrefl/selector`

**Environment variables (🛠️ developer)**

- `HREFL_HUB_SECRET` — shared password between hub and clients
- `HREFL_COPILOT_KEY` / `HREFL_ANTHROPIC_KEY` — AI provider key (optional)
- `HREFL_EMBEDDING_KEY` — embeddings server key (optional)

**Handy commands (🛠️ developer)**

- `drush hrefl:show <url>` — print the hreflang tags a page would emit
- `drush hrefl:seed <file.json>` — load a hand‑made mapping for a quick test
- `drush hrefl-hub:translate-match` — run AI translation‑assisted matching
