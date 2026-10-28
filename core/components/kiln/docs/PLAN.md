# Plan: kilndev.com — marketing site that sells Kiln, then accounts, licensing and downloads

## Context

kilndev.com is a fresh MODX 3.2.4 install (developed locally at https://kilndev.test, served by Kiln itself). It has to become:
1. the marketing site that **sells** Kiln (this first build);
2. the place customers create accounts, buy Pro, download installers from releases.kilndev.com, and manage their licenses and details;
3. the front end that talks to a new license-minting server, **license.kilndev.com**, on the same VM.

The shipped app already links to `kilndev.com`, `kilndev.com/compare` and `kilndev.com/buy`. It also fetches `https://license.kilndev.com/revoked.json` daily. Those URLs are fixed contracts.

Rob's decisions so far:
- **Theme:** dark-first, with the light/dark toggle kept. The toggle gets a cooler icon and a smooth animated transition.
- **Editing:** templates plus TVs/MIGX (not Fred).
- **Pricing:** not decided, so price text lives in a setting and the UI handles "TBA".
- **Payment provider:** decide later, so the commerce layer is provider-agnostic.

**Copy rule:** Kiln is never described as inspired by, based on, or a successor to anything. No comparisons. Note that `README.md` and `PLAN.md` in H:\SITES\KILN still contain old comparison wording; never lift copy from them.

---

## What exists (from the scan)

- **Generated base theme** at `assets/templates/kiln/assets/themes/kiln/`:
  - `css/site.css` is Bootstrap 5.3.8 inside `@layer kiln.framework`, plus `--brand-*` tokens and Inter `@font-face` rules.
  - `js/bootstrap.bundle.min.js` and `js/lib/*` hold local copies of Lenis, Swiper, GLightbox, AOS, Notyf, SweetAlert2 and others.
  - `images/` holds the logo PNGs and favicons. `fonts/` holds Inter 400–700.
  - **Kiln's Design tool regenerates `site.css` and `kiln-templates/modx/template.tpl` on every build.** We use `site.css` as the base and never edit either file.
- **Videos** at `assets/themes/kiln/video/`:
  - `kiln-hero-2.mp4`: 1910×886, 9 s, 1.5 MB. Dense embers rising from the bottom.
  - `kiln-hero.mp4`: 1454×918, 35 s, 3.6 MB. Drifting orange sparks that cool to white.
  - Both are on **pure black**, so they blend (`mix-blend-mode: screen`) seamlessly over any dark gradient.
- **Extras:** pdoTools, MIGX, SEO Suite, Fred, VersionManager. Rob is installing FormIt and Login now.
- **Database:** template 2 = the generated Kiln template. Resource 1 is Home. Resource 2's URI collides with Home. `site_name` is still "MODX Revolution".
- **Brand source art** in `H:\SITES\KILN\build\`: `logo-x - gradient.svg`, `logo-flat.svg`, `logo-wordmark-dark.png` / `-light.png`, `logo-offline.png`.
  - Logo gradient runs from #F04E3E to #FDB92E.
  - App UI uses #0f172a / #1e293b with a #38bdf8 accent.

---

## Architecture (all phases)

**One MODX namespace, `kiln`, at `core/components/kiln/`.** The core folder is already web-blocked.
- `elements/templates/`, `elements/chunks/`, `elements/snippets/`: static element files, so everything is diff-able and deployable with rsync.
- `lexicon/en/setting.inc.php`: Name, Description and Area for every `kiln.*` system setting, following the skill standard.
- `src/`: service classes for later phases (accounts, license API client, orders, webhook verification).
- `_build/setup.php`: an **idempotent** CLI script that creates or updates the category, templates, chunks, snippets, TVs (including MIGX TVs with inline formtabs/columns), system settings and starter resources. It also fixes `site_name` and resource 2's alias.
  - Locally it runs with the site's Kiln PHP. On the server it runs as `kilndev`.
  - Re-running it never overwrites a setting value or edited resource content.

**Front-end assets of our own** go in `assets/themes/kiln/`, next to the videos and outside the generated folder:
- `css/kiln-web.css`
- `js/kiln-web.js`
- `fonts/`
- `images/` (logo SVG, posters, OG image, icon sprite)
- `vendor/` (GSAP + ScrollTrigger, self-hosted)

**Load order:** `site.css` → `kiln-web.css`.

**Server layout (same VM, isolated as far as one VM allows):**
- `kilndev.com`: MODX (user `kilndev`), already live.
- `releases.kilndev.com`: static installers, already live.
- `license.kilndev.com`: **a new top-level Virtualmin server with its own Linux user (`kilnlic`) and its own database.**
  - The **public** vhost serves only `revoked.json`.
  - The **mint/revoke API** listens on a loopback-only vhost (127.0.0.1) with HMAC-signed requests, so only MODX on the same box can reach it.
  - The private key sits at `/home/kilnlic/keys/license-private.pem`, mode 0400, outside any docroot. MODX never sees the key.
  - **⚠ Conflict:** `H:\SITES\KILN\docs\LICENSING.md` says this server must be separate. This design is the compromise Rob asked for, and LICENSING.md will be updated to record it.

**Payment webhooks** go to MODX (`kilndev.com/hooks/payments`). MODX verifies the provider signature, records the order, then calls the loopback mint API and emails the serial through Resend SMTP.

---

## PHASE 1 (build now): marketing homepage and interior templates

### Design system — "Forge"

**Tokens in `kiln-web.css`:**
- Background: #0a0705 (warm black) with an #120c09 raised surface.
- Glass cards: `rgba(255,255,255,.035)` with a hairline `rgba(255,170,110,.14)` border.
- Text: #f5ece5, muted #b3a294.
- **Heat gradient:** #F04E3E → #FF7A1A → #FDB92E, taken from the logo.
- **Cool counter-accent:** #38bdf8, the app's own blue. Used sparingly for "running / OK / terminal" states, so the site visually ties to the app.

**Light mode:**
- Warm paper (#fcf5f2) with flame accents.
- Hero, showcase and CTA bands stay dark via Bootstrap 5.3's per-element `data-bs-theme="dark"`.
- Both modes come from one set of CSS custom properties.

**Type:**
- Self-hosted **Space Grotesk** (display headlines, tight tracking, 600/700).
- **Inter** for body text (already local).
- **JetBrains Mono** for terminal and code bits.
- All woff2, `font-display: swap`, with the hero font preloaded.

**Texture and light:**
- Film-grain noise overlay (an inline SVG filter, a few bytes).
- Radial "furnace glow" gradients.
- Cursor-tracked glow on cards: a CSS `--mx/--my` radial driven by a small pointermove handler.
- Gradient-border cards.

**Motion:**
- GSAP + ScrollTrigger (self-hosted) and Lenis smooth scroll (already local).
- Everything is gated by `prefers-reduced-motion`: animations off, videos paused on their poster.

**Icons:** a small inline SVG sprite (Lucide-style, ISC license) in `images/icons.svg`.

**Theme toggle (the "cooler icon"):**
- Custom SVG that morphs a **flame (dark) ↔ sun (light)**: paths and rays animate with CSS transforms, plus a small ember flicker on hover.
- **Smooth switch:** the View Transitions API (`document.startViewTransition`) does a circular clip-path reveal expanding from the button.
- Fallback: a 400 ms CSS color/background transition.
- Instant for reduced-motion users.
- Choice persisted in `localStorage['kiln-scheme']`, the same key the generated theme uses. A pre-paint inline script prevents a flash.

### Shared chunks

| Chunk | Contents |
|---|---|
| `cmp.Head` | Meta, preload, CSS, theme bootstrap script, SEO Suite meta, OG/Twitter tags, `schema.SoftwareApplication` JSON-LD |
| `cmp.Header` | Glass sticky navbar that condenses on scroll; pdoMenu items; theme toggle; Account link (Sign in / My account once Login is wired); flame-gradient "Download free" button with glow; mobile offcanvas menu |
| `cmp.Footer` | Big ember-glow footer: logo + tagline, columns (Product / Get Kiln / Support / Legal), platform badges, version line from a setting, © year |
| `cmp.Scripts` | Bootstrap bundle, Lenis, GSAP, `kiln-web.js` |
| `cmp.CtaBand` | Reusable "Fire it up." band with sparks video and download buttons |
| `nav.tpl.*` | pdoMenu row templates |

### Template `tpl.Homepage`: sections, top to bottom

1. **Hero** (full viewport)
   - Background: `kiln-hero-2.mp4` screen-blended over a molten radial gradient rising from the bottom, plus grain. A poster WebP is captured from the video frame.
   - Eyebrow pill: "v0.1.6 · Windows & macOS".
   - Headline: **"Heat up your dev stack."** The words "dev stack" carry a slow heat-shimmer gradient.
   - Subhead (draft): *"Real Apache or nginx, any PHP version per site, MariaDB, trusted HTTPS and a local mail catcher — running on your own machine in minutes. Self-contained from the very first click."*
   - CTAs: **Download for [detected OS]** (primary) and **See what's inside** (ghost).
   - Below the CTAs: a tilted glass "app window" product visual with a glow underneath. It is an HTML/CSS mock of the Kiln dashboard (sites list, green status dots, PHP pills, HTTPS locks) in the app's own colors, swappable for real screenshots.
2. **Stack marquee**
   - Infinite, edge-faded ticker of text pills: Apache · nginx · PHP 7.0 → 8.5 · MariaDB · phpMyAdmin · Mailpit · Composer · WP-CLI · WordPress · MODX · Joomla · Grav · Bootstrap · Tailwind · Bulma.
   - Text only, no third-party logos.
3. **Three pillars, with counters**
   - "Two web servers. At the same time."
   - "Every PHP version. Per site."
   - "Zero system clutter. One click to remove it all."
4. **Feature bento grid** (the main sell)
   - Asymmetric tiles; each has its own micro-animation triggered on view. Content comes from MIGX TV `features`: icon, title, body, tile size, visual key.
   - Tiles:
     - **Apache + nginx side by side**: per-site server switch animates.
     - **PHP per site**: version pills slot into sites.
     - **HTTPS your browser trusts**: an address bar padlock turns green.
     - **Mail never leaves your machine**: emails drop into a local inbox with an unread badge.
     - **Design studio**: palette swatches shuffle; framework chips.
     - **One-click CMS installs**: progress ring → "Ready".
     - **Test on your phone**: a phone frame shows the LAN URL.
     - **Push & pull to your host**: a sync arrow with a file-diff preview.
     - **Per-site terminal**: a typed line, `php -v → 8.4`.
     - **Move a site in one file**: a `.kiln` doc icon.
     - **Setup check**: a list of checks ticking green.
5. **"From download to https://mysite.test"**
   - A scroll-pinned four-step story: install → setup check goes all green → add a site → open it over HTTPS.
   - Sticky left copy; the right panel morphs per step. Content from MIGX TV `steps`.
6. **Deep-dive rows** (alternating, image/visual + copy): Design studio; Sync & deploy; database tools; mail catcher.
7. **"Your machine stays yours"** (trust band)
   - Portable data folder; no system-wide installs; signed and notarized installers; clean, complete uninstall; test mail stays local; LAN access only on your network.
8. **Platforms**: Windows 10/11 · macOS Apple Silicon · macOS Intel. Cards link to `/download`.
9. **Free vs Pro teaser**
   - Two glowing cards.
     - **Free:** 1 site, everything including mail, CLI tools and file sync.
     - **Pro:** unlimited sites, one-click CMS installs, database sync, site-rules editor, Design builds.
   - Price comes from setting `kiln.price_pro_display`. When empty it shows "Launch pricing coming soon".
   - Buttons go to `/compare` and `/buy`.
10. **FAQ**: accordion from MIGX TV `faqs`, plus `schema.FAQ` JSON-LD.
11. **`cmp.CtaBand`**: "Fire it up." over the `kiln-hero.mp4` sparks, with OS download buttons.
12. **Footer**

**Rules applied to all copy:**
- No AI, remote preview, seat or auto-update claims.
- No exact-time or performance numbers that aren't verified.
- No comparisons to other products.

### Template `tpl.Interior`

- **Compact dark hero band:**
  - Breadcrumbs (pdoCrumbs), eyebrow, H1, subtitle (TV `heroSubtitle`).
  - Background via TV `heroStyle`:
    - `embers`: the dimmed `kiln-hero.mp4` with `preload=none`, started by an IntersectionObserver.
    - `glow`: CSS gradient only, no video.
    - `plain`
- **Body:** `.kiln-prose` typography for `[[*content]]` (headings, lists, code, tables, callouts, images).
- **Optional sticky table of contents** (TV `showToc`), built client-side from the H2s, with a scroll-spy.
- **Optional `cmp.CtaBand`** (TV `showCta`, default on). Shared header and footer.

### Starter resources (created by `setup.php`)

| Path | Template | Content |
|---|---|---|
| Home | `tpl.Homepage` | Starter copy |
| `/features` | Interior | Starter copy |
| `/compare` | Interior | Free-vs-Pro table. The app already links here. |
| `/buy` | Interior | "Pro launches soon" page. The app links here. |
| `/download` | Interior | OS cards with file names, versions and sha256 values from `kiln.*` settings, until `versions.json` exists |
| `/contact` | Interior | FormIt form; mail through Resend SMTP |
| `/privacy`, `/terms` | Interior | Stubs |
| `/404` | Interior | Error page |

### System settings (namespace `kiln`, with lexicon)

- `kiln.current_version`
- `kiln.download_win_url`, `kiln.download_mac_arm_url`, `kiln.download_mac_x64_url`, plus a matching `*_sha256` setting for each
- `kiln.price_pro_display`
- `kiln.support_email`

**Download button:** `kiln-web.js` detects Windows vs macOS. For Mac it offers both chips (Apple Silicon / Intel) by name, because browsers can't reliably tell them apart.

### Video handling

- Attributes: `autoplay muted loop playsinline preload="metadata"`, with a poster.
- An IntersectionObserver pauses videos when off-screen.
- Videos are skipped (poster only) when `prefers-reduced-motion` or `Save-Data` is set.
- Posters are generated by grabbing a frame with headless Edge (already proven to work here).

### Files created or changed in Phase 1

- `core/components/kiln/elements/templates/tpl.Homepage.html`, `tpl.Interior.html`
- `core/components/kiln/elements/chunks/`: `cmp.*`, `nav.tpl.*`, `schema.*`, `migx.tpl.*`
- `core/components/kiln/lexicon/en/setting.inc.php`
- `core/components/kiln/_build/setup.php`
- `assets/themes/kiln/{css/kiln-web.css, js/kiln-web.js, fonts/*, images/*, vendor/gsap/*}`
- **Not touched:** anything in `assets/templates/kiln/` (generated) and template 2.

---

## Later phases (planned, in order)

> **Licensing, accounts and purchases were re-planned on 2026-10-27.** The approved
> plan is `H:\SITES\KILN SERVER\licensing\docs\PLAN.md` and replaces Phases 2, 3, 4,
> 5 and 7 below where they differ (custom accounts with 4-letter codes instead of the
> Login extra; more plans; teams; trials). Built locally so far:
> - **Licence server** (`H:\SITES\KILN SERVER\licensing`, license.kilndev.test):
>   `php tests/api.php`, `node tests/golden.mjs`.
> - **Accounts** (`/account/…`): `src/Accounts.php`, `Codes.php`, `Social.php`,
>   `AccountController.php`; test `php tests/accounts.php`. Self-serve 7-day trial
>   button only while `kiln.trials_public` is on.
> - **Manager page Extras → Kiln Licences**: `controllers/licenses.class.php`,
>   `src/Processors/*` (called by class name through MODX's connector; Administrators
>   or sudo only, manager context only), UI `assets/themes/kiln/mgr/licenses.js`;
>   test `php tests/manager.php`. Mint any plan for any email, 7-day trial,
>   revoke/unrevoke/extend/renew/transfer/resend, teams and seats, customer view,
>   audit log.
> - **Buying** (`/buy`): `src/Shop.php` (sales switch, prices, cart, orders, webhooks:
>   fulfil / renew / refund), `src/BuyController.php`, `src/Payments/` (PaymentProvider,
>   TestProvider), tables `kiln_orders`, `kiln_order_items`, `kiln_payment_events`,
>   webhook page `/payment-webhook?provider=…`; manager Orders tab; test `php tests/buy.php`.
> - **The sales switch** is one setting, `kiln.sales` = soon | open | paused (area Sales),
>   flipped in one click at the top of Extras → Kiln Licences (which also clears the cache).
>   Prices: `kiln.price_annual`, `kiln.price_lifetime`, `kiln.price_team_seat`.
>   `kiln.payments_test_mode` must be **off** on the live site; it is on locally.
> - **Paddle** is the payment provider (chosen 2026-10-27; merchant of record, so Paddle
>   charges and files sales tax/VAT). `src/Payments/PaddleProvider.php`, checkout overlay
>   `buy.PaddleCheckout` + `assets/themes/kiln/js/kiln-paddle.js`, settings in area Paddle,
>   `_build/paddle-setup.php` makes the products and webhook destination. Test
>   `php tests/paddle.php` (runs against a stand-in API, `tests/paddle-mock.php`). Setup
>   steps for Rob: `docs/PADDLE.md`. Still needs Rob's Paddle sandbox account to try with
>   real Paddle.js, then live account approval.
> - **Next:** Paddle sandbox trial run, then app 0.1.7 renewals, then go-live.

**Phase 2 — Accounts (Login extra)**
- Register with email confirmation, log in, forgot/reset password, update profile, change password.
- `Customers` user group; an `Account` resource group protects `/account/*`.
- Cloudflare Turnstile on register and contact forms.
- Mail through Resend SMTP (`mail_use_smtp`), from `hello@kilndev.com`. Transactional email chunks: `email.*`.

**Phase 3 — license.kilndev.com**
- **Setup:** new Virtualmin server (user `kilnlic`, PHP 8.3-FPM, its own database).
- **PHP minter.** It must match `scripts/licenseLib.mjs` exactly:
  - RSA-2048 PKCS#1 v1.5 SHA-256 (`openssl_sign`, `OPENSSL_ALGO_SHA256`)
  - base64url without padding
  - `KILN1.` prefix
  - payload with sorted keys, `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`
  - **Golden test:** the Node `license.mjs` must verify PHP-minted serials, and vice versa.
- **Database tables:** `licenses` (uuid, email, tier, iat, exp, seats, order_ref, status, revoked_at), `audit_log`.
- **Loopback API (HMAC-signed):** `POST /v1/mint`, `POST /v1/revoke`, `POST /v1/unrevoke`, `GET /v1/licenses?email=`.
- **`revoked.json`:** regenerated and signed on every revoke change. It must reproduce `JSON.stringify({revoked, updated, v})` byte for byte, with `updated` always increasing.
- **Blockers before go-live:** EBS snapshot schedule plus Virtualmin S3 backups; a dedicated Resend key `kiln-licensing`; update LICENSING.md.

**Phase 4 — Commerce (built 2026-10-27 with Paddle; see the status block above)**
- A provider-agnostic `PaymentProvider` interface: create checkout, verify webhook, map events.
- `kiln_orders` table with idempotency keyed on the provider event id.
- Purchase → mint → email serial. Refund or chargeback → revoke.
- The `/buy` page becomes the live checkout.
- Pricing model and provider are picked before this phase starts.

**Phase 5 — Customer portal (`/account`)**
- Dashboard with license cards showing the friendly License ID, serial with a copy button, issued/expiry dates and status.
- Download installers with sha256, order history and receipts, profile and email change (re-verified), resend license email.
- Seat management is added when Phase 5 of the app (seat activation) lands.

**Phase 6 — Releases integration**
- `releases.kilndev.com/versions.json` (per platform/arch: version, url, sha256, notes URL, minimum OS).
- A cached `kiln.Versions` snippet feeds `/download`, the header button and the footer version, replacing the Phase 1 settings.
- A changelog / release notes collection.

**Phase 7 — Admin CMP "Kiln Licenses"** (MODExt grid)
- Search customers and orders; mint comp/tester licenses; revoke/unrevoke; resend.
- Replaces the local license GUI for day-to-day use.

**Phase 8 — Launch polish**
- SEO Suite meta, OG image, sitemap, `SoftwareApplication` and FAQ schema, Lighthouse ≥ 95.
- Legal pages and GPL source offer wording; cookie consent only if analytics is added.
- Deploy pipeline: rsync as `kilndev`, then `setup.php` on the server.

---

## Verification (Phase 1)

1. Run `setup.php` locally with the Kiln PHP CLI. Re-run it to prove it is idempotent (no duplicates, values untouched). Clear the MODX cache.
2. Load https://kilndev.test/ and the interior pages. The MODX error log stays clean.
3. Take headless Edge screenshots at 1440, 1024, 768 and 390 px in both themes, and review them visually: hero video blend, bento, pinned steps, toggle transition.
4. Reduced motion (Edge `--force-prefers-reduced-motion`): no animation, videos paused on their posters.
5. Lighthouse (`npx lighthouse` against kilndev.test): performance and accessibility ≥ 90 at this stage. Contrast checks on both themes.
6. Confirm `/compare` and `/buy` resolve. The app links to them.
7. Deploy to kilndev.com only after Rob signs off locally (rsync as `kilndev` + `setup.php`).

## Needed from Rob (not blocking the start)

- Real app screenshots: Dashboard, Sites, PHP, Design, Mail, Sync. The hero mock ships first.
- Confirm FormIt and Login are installed. Also confirm which PHP version kilndev.test runs, for the CLI.
