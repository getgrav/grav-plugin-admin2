# v2.0.0-beta.10
## 04/19/2026

1. [](#improved)
    * Page visibility signal in Tree, List, and Columns views switched from a 60% opacity dim on the title to a two-tone page/folder icon: **visible** pages use the accent color (matches the rest of the admin chrome), **non-visible** pages drop to the muted grey used for secondary text. Keeps titles at full contrast — the previous dim was hard to read against the dark background — while still giving an at-a-glance signal in the same spot readers already look. Miller's active-row white-on-accent treatment still wins when a row is selected.
    * Background refreshes now run **silently** across the admin. Previously, the dashboard's 60-second poll and every mutation-triggered refresh re-entered the same code path as the initial load — flipping the `loading` skeleton and resetting the number-tweens, so counters re-animated from zero and cards re-faded-in every minute (and every time a page was saved anywhere). Pages list / tree / columns views took the same approach: a save anywhere fired a `pages:*` event, and each view wiped its entire children cache, showed a skeleton, and refetched from scratch. Split into explicit "fresh load" vs "background refresh":
        * Dashboard: `loadDashboard({ silent })` — silent path skips the skeleton flip and the animation reset. The poll, all `pages:*` / `users:*` / `plugins:*` / `gpm:*` invalidations, and the post-Update-All / post-Upgrade-Grav refreshes all run silent. Only the first mount and the user-clicked **Refresh** button animate.
        * Tree view: cache-wipe refetch replaced with `silentRefresh(parentRoutes[])`. Uses the invalidation event's id (e.g. `pages:update:/blog/post-1`) to derive the parent route, then refetches just that parent's children into the cache — no `rootLoading` flip. Root is kept fresh as insurance. Tab-refocus silently refreshes all currently-cached parents instead of wiping them.
        * List view: `loadPages(silent)` — invalidations and focus events refetch the current page of results silently; no skeleton flip.
        * Miller (Columns) view: `silentRefreshColumn(parentRoute)` — refetches only the column(s) containing the affected page, preserving the user's selection trail and downstream columns. Previously, any mutation reset the view back to the root column.
        * Result: the dashboard no longer "re-animates" every minute, and the pages browser no longer flashes a skeleton when a page is saved elsewhere on the site.
2. [](#bugfix)
    * Plugin / theme installs now pull in missing blueprint dependencies automatically instead of silently installing only the requested package — e.g. installing `shortcode-ui` now also installs `shortcode-core` if it's missing, with each dependency surfacing as its own success toast ("Plugin '…' installed (dependency)") before the main package's toast. Dependency resolution runs server-side via `GPM::getDependencies()` (same path admin-classic uses, so version constraints and PHP/Grav requirements are checked); the new `dependencies: string[]` field on the `/gpm/install` and `/gpm/update` responses drives the UI side. Applies to `AddPluginModal`, `AddThemeModal`, and the update flow on plugin/theme list + detail pages. Failure modes (needing a newer Grav core, a newer PHP version, an incompatible version constraint, or a mid-install failure after some deps already succeeded) now surface the API's error detail in the toast instead of a generic "Failed to install" message, so users can see exactly why the install stopped and what partial state the system is in. Requires grav-plugin-api ≥ beta.10.
    * Self-hosted font files (`/fonts/*.ttf`) are now served correctly from the admin2 route. The static-asset gate in `admin2.php` previously only intercepted `/_app/` — anything under `/fonts/` fell through to the SPA router and returned the HTML shell, so browsers reported `OTS parsing error: invalid sfntVersion` and the Google Sans option in Settings → Appearance silently fell back to Inter. The gate now also matches `/fonts/`, `/robots.txt`, and `/favicon.ico`, and the MIME map adds explicit `font/ttf` and `font/otf` handlers. The SPA shell declares an inline `<link rel="icon" href="data:,">` to silence the default browser favicon request.
    * Pages **Tree view** no longer crashes with `RangeError: Maximum call stack size exceeded` on sites where the home page has sub-pages. The tree's recursive `treeRow` snippet keyed its expansion/cache state off `page.route`, but the home page's public route is `/` — the same value used as the tree's root-parent marker, which is pre-seeded into `expandedRoutes` so the top-level children render on load. When home had any children, rendering its row satisfied `expandedRoutes.has('/')`, looked up `childrenCache['/']` (which holds the root-level page list — including home itself), and recursively rendered the same set of rows again, blowing the stack within a handful of frames. Identity keys inside the snippet now use `pageApiRoute(page)` (`raw_route || route`), so home is tracked as `/home` in the expansion and cache maps and can no longer collide with the root marker. Expanding home now correctly fetches and displays its children via `getChildren('/home')`.

# v2.0.0-beta.9
## 04/19/2026

1. [](#new)
    * Accent color **Custom** picker in Settings → Appearance — hue (0–360°) and saturation (0–100%) sliders let you dial in any brand color while the theme's lightness clamp keeps contrast consistent across light and dark modes. The gradient-filled sliders preview the result live; the panel auto-expands for users whose stored color doesn't match a preset.
    * Added **Grav** accent preset (hue 271 / sat 91 — the purple used on the new getgrav.org design) and promoted it to the default accent color for new installs.
    * **Font picker** in Settings → Appearance with five built-in variable typefaces: **Google Sans** (self-hosted, new default, matches the marketing site), Inter, Public Sans, Nunito Sans, Jost. Each option renders its label in its own typeface for live preview; the chosen font persists per-install alongside the other appearance preferences and applies globally via a `--font-sans` CSS variable.
2. [](#improved)
    * Dark-mode primary color is now rendered at Tailwind-500 lightness (L=65) instead of L=70 with a +8 saturation boost. Toggles, primary buttons, focus rings and every other `--primary`-driven element now match the canonical 500 shade of the chosen hue (e.g. Grav purple → ~#B166F8) instead of a slightly washed-out neon 400.
    * Dark-mode `--popover` token raised from `hsl(240 10% 3.9%)` (~#09090B, near-black) to `hsl(240 4.5% 14.5%)` so floating surfaces sit in the same grey family as cards and inputs. Fixes the visible inconsistency where custom dropdowns (Selectize, Pages picker, File picker, Icon picker, DateTime calendar, language switcher, cache-clear menu, floating widgets) rendered much darker than native `<select>` controls that used `bg-muted`.
    * Pages **columns (Miller) view** now surfaces per-page publish/visibility state at a glance: an inline amber **Draft** pill renders next to the title for unpublished pages, invisible pages dim to 60% opacity (consistent with Tree and List views), and the preview panel shows a **Visible** badge for pages that appear in nav alongside the existing Published/Draft and Has-children badges.
    * Tree and List views also dim invisible pages (those with `visible: false` in frontmatter or missing an order prefix) to 60% opacity on the title + route block. Composes naturally with the existing italic-muted styling for untranslated pages so the two signals remain distinct.
    * Page Info sidebar on the edit screen now updates immediately after saving a Published or Visible change — previously required a page reload to pick up the new value. Requires grav-plugin-api ≥ beta.9.
    * Draft / hidden pages in Tree, List, and Columns views now correctly report their published / visible state in the API response. Previously the flex-indexed listing endpoint returned stale "true" values so every draft looked published and every hidden page looked visible. Requires grav-plugin-api ≥ beta.9.
    * Dashboard **Refresh** button now flushes the GPM remote-manifest cache (equivalent to `bin/gpm index -f`), so clicking it picks up newly released plugin / theme / Grav versions instead of returning the stale local cache. The 60-second auto-poll and invalidation-triggered reloads still use the cached path.

# v2.0.0-beta.8
## 04/17/2026

1. [](#new)
    * **Copy page** restored in the Pages edit toolbar (parity with admin-classic). Duplicates the current page into the same parent — picks the next free `slug-N`, increments the trailing number in the title (or appends ` 2` if none), then navigates to the new page's edit screen.
2. [](#improved)
    * Pages edit toolbar is now responsive. Below `lg` (1024px) the Normal/Expert toggle, Save and Undo collapse to icon-only; Preview, Copy and Delete are always icon-only and sit to the left of the Normal/Expert toggle. Below `sm` (640px) the toolbar wraps onto its own row beneath the title so it stops crowding the page title on narrow viewports.
3. [](#bugfix)
    * Home page now works correctly in the Pages UI. All page list / tree / columns views, the page-navigator D-pad, and the edit screen address the home page by its structural `raw_route` (typically `/home`) instead of the public `/` alias that the API router doesn't match — fixes the empty preview in columns view and the "Failed to load page" error when editing Home. Direct navigation to `/pages/edit/` also resolves to the home page automatically.
    * Plugin and theme descriptions render inline markdown (links, bold, emphasis) in detail panels instead of showing raw `[text](url)` / `**bold**` syntax. Truncated list-card descriptions strip the markdown to plain text so the one-line summary stays readable. Uses the new `description_html` field from grav-plugin-api beta.8.
    * Pages with template-dependent shortcodes in their body (e.g. `[poll]`) no longer fail to load a preview in the Miller columns view. The page-summary API endpoint now falls back to plain-text when shortcode rendering throws (requires grav-plugin-api beta.8).

# v2.0.0-beta.7
## 04/17/2026

1. [](#new)
    * Symlink indicator in the Plugins and Themes listings — a small VS Code-style corner arrow (↳) renders to the right of each row (next to the Enabled / Disabled / Active pill) for any package installed via symlink, with a native `Symlinked` hover tooltip. Reads the new `is_symlink` field from grav-plugin-api beta.7.
    * Dashboard Updates card redesigned for hierarchy: prominent purple Grav-core callout (version arrow `v{current} → v{available}`, filled-purple **Upgrade Grav** button) sits above an amber package panel (per-row version chips, filled-amber **Update All** button). Distinct button colors differentiate core upgrades from routine package updates at a glance.
    * Grav core card degrades to an explanatory message ("installed via symlink — upgrade manually") and hides the upgrade button when `is_symlink` is reported by the API.
    * **View site** button restored in the top menubar (globe icon + "View site" + external-link glyph) — opens the Grav frontend (`auth.serverUrl`) in a new tab. Had been dropped during the auth transport refactor.
    * **Unsaved-changes indicator** restored and now uniform across every edit surface — a pulsing amber dot in a bordered pill sits in the toolbar whenever there are pending changes. Adopts the shared `UnsavedIndicator` tri-state (saving spinner / green "saved" check / amber pulsing dot) when auto-save is enabled. Wired up on: Pages edit, Config (system / site / media / security), Plugins config, Themes config, Users edit, Flex-objects edit, and the blueprint-mode Plugin page.
    * Pages listing now distinguishes "implicit default" pages (those stored as a bare `default.md` with no language-suffixed variant) from genuinely untranslated pages. Implicit defaults render in normal text with a muted default-language badge; only pages that have explicit translations but lack one for the active language are shown italic + muted. Badge highlighting uses the new `explicit_language_files` signal, so the active-lang badge stays muted when it's served by the `default.md` fallback and highlights only when there's a real `default.<lang>.md` on disk.
    * Miller / columns view reached feature parity with Tree and List: route paths now render below each page title, and translation badges render inline beside the title (same styling + highlighting rules).
    * Page editor Save-as-{lang} dropdown: when the page is a bare `default.md` and the chosen target matches the site's default language, the action now routes through the new `POST /pages/{route}/adopt-language` API — renaming the file in place instead of creating a duplicate `default.<lang>.md` alongside the original. The dropdown correctly surfaces "Save as {defaultLang}" even when Grav reports the default lang in `translated_languages` (it always does when `default.md` exists), using `explicit_language_files` to tell whether the real file exists.
    * Tree and List page views now perform **full-site search** against the server (`GET /pages?search=`) instead of only filtering the currently-visible rows. Previously both views were paginated / lazy-loaded and the search box could only match pages that had already been fetched, which made the search feel broken on large sites. When the search input is empty both views revert to their normal paginated / tree behavior; searches are debounced at 250ms and capped at 500 results.
2. [](#improved)
    * All package update and Grav upgrade actions (single-package update, Update All, Upgrade Grav) now require explicit confirmation through the shared `ConfirmModal`. Previously these fired immediately on button press, which is risky for destructive / long-running writes on a live site.
    * X-API-Token header is now the default JWT transport for all API calls, aligning with grav-plugin-api beta.7. Fixes login / reauth on FastCGI hosts (e.g. MAMP) that strip the `Authorization` header.

# v2.0.0-beta.6
## 04/16/2026

1. [](#new)
    * Updates control surface across the SPA: Dashboard "Updates" card gains an "Update All (N)" button and a prominent amber/orange "Upgrade Grav to vX" button (disabled + tooltip when Grav is installed as a symlink)
    * Plugins page: "Update All (N)" header action plus a per-row "Update to vX.Y" button in the detail panel
    * Plugin config page: amber "Update available" pill next to the version and an "Update to vX.Y" button in the action bar
    * Themes page + theme config page: mirrors the plugin update surface
    * Sidebar footer now shows the running `Grav vX` / `AdminN vX` versions next to the collapse chevron (admin2 injects both into `window.__GRAV_CONFIG__`)
2. [](#improved)
    * Confirmation prompts for updates route through the shared `ConfirmModal` (via `dialogs.confirm`), matching the rest of the SPA
    * Dashboard, Plugins, and Themes polling now reflects the refreshed updatable state immediately after any update / upgrade call
3. [](#bugfix)
    * `GET /gpm/updates` now counts Grav itself in `total`; the dashboard previously said "1 update available" when both a plugin and Grav core had pending releases

# v2.0.0-beta.5
## 04/16/2026

1. [](#new)
    * Server-side bootstrap hijack — frontend requests on sites with zero user accounts are now redirected to the admin route (parity with admin-classic), closing the window where a stranger could reach the admin before the site owner did
2. [](#improved)
    * All admin-next SPA `localStorage` keys (auth tokens, preferences, theme, content language, i18n cache) are now scoped by `__GRAV_CONFIG__.basePath` so multiple Grav installs on the same browser origin (e.g. `localhost/site-a`, `localhost/site-b`) no longer share or clobber each other's session and settings
    * Self-contained `anyUsersExist()` helper so the hijack does not require admin-classic to be installed
3. [](#bugfix)
    * No-user redirect now passes a route-local path to `Grav::redirect()` (the framework prepends the site root itself); previously double-prefixed on installs mounted under a subpath
    * No-user hijack excludes the API plugin's own route prefix so the SPA's `/auth/setup` probe can reach the API

# v2.0.0-beta.4
## 04/15/2026

1. [](#new)
    * Registers `site.login`, `admin.login`, `admin.super` permissions so the user-edit ACL UI works without admin-classic enabled
    * Contextual frontend launcher in the header — opens the current page (when editing) or the site root in a new tab
    * Compact `<UnsavedIndicator>` pill (pulsing amber dot for unsaved, green check for saved, spinner for saving) wired into every save-button view: pages, config, users, plugins, themes, flex-objects
    * Dashboard "Backups" card promoted to a full-width row with filename + "Pre-migration" badge for migration backups
    * Setup wizard route + login-page redirect when no user accounts exist
    * Reauth modal probes `/auth/setup` and bounces to the wizard when the underlying account no longer exists
2. [](#improved)
    * Permissions editor: scoped crowns (`admin.super` → `admin.*` only, `api.super` → `api.*` only), section badges ("Admin Classic is deprecated in Grav 2.0", "API & Admin2 Access"), Admin section collapsed by default
    * Page navigation prefers the API's new `raw_route` so home-page editing resolves to `/home` instead of failing on `/`
    * Brightened dark-mode `--popover` token so dropdowns no longer read as near-black against the slate UI
    * Top Pages widened to `lg:col-span-2` to balance the dashboard grid after Backups moved out
3. [](#bugfix)
    * Edit-page navigation no longer 404s on the home page

# v2.0.0-beta.3
## 04/15/2026

1. [](#new)
    * Support for first user creation

# v2.0.0-beta.2
## 04/15/2026

1. [](#bugfix)
    * Fixed dynamic path issue

# v2.0.0-beta.1
## 04/14/2026

1. [](#new)
    * Initial alpha release
    * SvelteKit single-page application served from `app/` directory
    * PHP wrapper serves static assets and SPA shell, injects runtime config via `window.__GRAV_CONFIG__`
    * Route configurable via `plugins.admin2.route` (defaults to `/admin2`)
    * All data operations routed through the Grav API plugin
    * `bin/build.sh` for building the sibling `grav-admin-next` SvelteKit project into this plugin
