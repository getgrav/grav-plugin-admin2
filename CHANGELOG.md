# v2.0.0-beta.13
## 04/24/2026

1. [](#new)
    * **Password strength meter + requirements modal** on every password entry surface — first-run setup, password reset, new-user creation, and the user profile edit form. Reads the configured `system.pwd_regex` (or the new optional `system.pwd_rules` list of labeled rules) via `GET /auth/password-policy` and renders a live rule checklist plus a single horizontal meter. Color flips to green only when every required rule passes (so the user knows "this will submit"); fill percentage keeps climbing with a lightweight entropy score, so a barely-passing password sits mid-green and a long, diverse one pushes to full. A small `Requirements` hint button next to the field opens a modal listing each rule with live met/unmet indicators. The setup-status endpoint piggybacks the policy so `/setup` gets it in one round-trip; all three legacy flows cache the policy via a shared Svelte store. Includes a reveal (eye-icon) toggle on every password field. Requires grav-plugin-api ≥ beta.13.
    * **Real-time collaborative editing on pages**, opt-in via Settings → Editing → "Real-time Collaboration". Multiple users can edit the same page simultaneously and see each other's changes character-by-character, with named cursors in the content editor and live presence avatars in the topbar. The whole blueprint is mirrored into a shared Yjs document (not just the markdown body) so concurrent edits to title, taxonomy, options-tab fields, etc. all merge cleanly per-key — long-form text fields (markdown / textarea / yaml / editor) flow through `Y.Text` for character-level CRDT, while toggles, selects and dates use last-write-wins on the enclosing `Y.Map`. The transport is capability-driven: the client probes `GET /sync/capabilities` and prefers `MercureProvider` (sub-100ms SSE) when the server advertises a Mercure hub, falling back to `PollingProvider` (1-second short-poll) otherwise. Editor integration uses y-prosemirror for editor-pro and y-codemirror.next for the markdown CodeMirror; both share the same Y.Doc as the form binding so every editor sees a single source of truth. Requires grav-plugin-api ≥ beta.13, grav-plugin-sync ≥ 1.0, grav-plugin-editor-pro ≥ 2.0.1, and (optionally for low-latency) grav-plugin-sync-mercure.
    * **Page-editor presence + Normal/Expert toggle relocated to the global topbar.** The page edit toolbar was visibly cramped on standard 13–14" laptops: the per-page actions (history, language, drafts/published, page navigator, copy/delete, save/save-as) plus the collab presence cluster and the editor-mode segmented control were fighting for the same row. Presence (user avatars + sync status badge) now sits to the right of the environment selector at the top of the app shell, where it's globally visible and matches the global nature of "who else is here." The Normal/Expert pill moves up next to the View site button — close to the other top-of-window editing chrome and out of the per-page button row. Both slots are populated only by the page edit route (via a small `pageEditorBar` Svelte 5 store) and clear on route teardown so the topbar reverts to its plain shape elsewhere in the admin.
2. [](#bugfix)
    * List/array fields (tags, taxonomies, multi-selects, etc.) now CRDT-merge concurrent additions instead of last-write-wins clobbering them. Two users adding tags to the same page would previously each call `pushLocal` with their own local array — whichever got serialized last won, and the other user's tag silently disappeared on the next sync. The shared form binding now stores arrays as `Y.Array` and `pushLocal` runs a multiset diff so items present in old-but-not-new get deleted, items present in new-but-not-old get appended (with duplicate handling via sentinel marking). Pure reorders and arrays of objects (repeater rows) still wholesale-replace — a per-item refactor on the field-component side is the path forward for those, but the storage shape is now correct for it. Affects every list/array field surfaced through the page editor's blueprint form when collab is enabled.
    * Two users opening the same fresh page at the same time no longer end up with doubled-up content. With the live edit feature on, both browsers would observe an empty Yjs document locally, push their own seed update, and the server would land both copies into the log — for a `Y.Text` field that meant the title and body got duplicated (`"HelloHello"`-style). The sync substrate now arbitrates under an exclusive file lock: a new `POST /sync/pages/{route}/init` endpoint accepts a seed only when the log is empty, and admin-next builds its seed in a throwaway `Y.Doc` so the live document isn't touched until the server confirms a win. Losers receive the canonical state inline, so the second tab catches up in the same request without an extra pull. Requires grav-plugin-sync ≥ today's tip.
    * Cmd-Z / Cmd-Y / the toolbar undo+redo buttons in the markdown CodeMirror editor no longer roll back peer edits when collaborative editing is active. The CodeMirror `history()` extension and `historyKeymap` operate on CM transactions, which include remote ops applied by `yCollab` — so an undo there could erase a co-editor's keystrokes. The editor now substitutes an explicit `Y.UndoManager(yText)` (passed to `yCollab` as the `undoManager` option, with `yUndoManagerKeymap` taking over Cmd-Z bindings) when a shared `Y.Text` is supplied; the toolbar undo/redo also dispatches against that manager. y-codemirror.next's view plugin registers its sync-config origin as the only tracked origin, so peer edits (which arrive with a different origin) are excluded from the undo stack by construction. Pairs with the equivalent fix on the editor-pro side. Requires grav-plugin-editor-pro ≥ 2.0.1.
    * Plugin / theme / config / user / flex-object detail pages no longer return a spurious `409 Conflict` ("Configuration was modified elsewhere. Please reload.") on the first save when the admin sits behind Apache + mod_deflate (or an nginx build with gzip/br). The compression filter weakens the `ETag` response header by appending `-gzip` (or `-br`) to mark it as a compressed variant — but the client echoes that suffixed value back in the next `If-Match`, and PATCH responses (uncompressed) generate the bare hash, so the strict comparison always failed. The API's `validateEtag()` now strips transport suffixes (`-gzip`, `;gzip`, `-br`, `-deflate`) and weak-validator markers (`W/`) before comparing; admin-next also normalizes ETags at extraction time via a shared `extractEtag()` helper. Invisible locally (`php -S`, MAMP) — only repros behind compressing reverse proxies. Requires grav-plugin-api ≥ beta.13.
    * User listing no longer surfaces phantom entries for stray files in `user/accounts/`. Grav's Flex `FileStorage::buildIndex()` indexes every file in the accounts folder without filtering by extension, so backup/snapshot files dropped by other plugins (e.g. revisions-pro's `.rev` snapshots) showed up as clickable "users" in the Users list. `UsersController::indexViaFlex` now constrains the collection to keys matching the username pattern `[a-z0-9_-]+` before search/sort/pagination run.
    * Blueprint password fields in the user profile edit form now render with the strength meter + requirements hint (previously rendered as a plain password input via `TextField`). Size prop is honored so the password input matches the width of neighbouring text inputs.
    * Mutation requests (`DELETE`, `PATCH`, `PUT`) no longer hard-fail on shared-hosting nginx configurations that 405 non-standard verbs at the edge. The API client now detects a 405 on a mutation verb, flips a `sessionStorage` flag, and transparently retries the request as `POST + X-HTTP-Method-Override: <method>`; subsequent requests in the same session skip the failed first attempt and use the compatible path directly. Session-scoped so a deploy swap or new sign-in re-detects from scratch. Requires grav-plugin-api ≥ beta.13 for the server-side rewrite middleware.
    * Theme / plugin / user blueprint `type: file` fields now upload to the location declared by the blueprint's `destination:` property (e.g. `theme://images/logo`, `self@:images`, `user://assets`) instead of always routing through the page-media endpoint. The old path built URLs like `/pages//media` when the form had no owning page, which collapsed on the server and surfaced as a "network error" toast. `FileField` now reads `field.destination` and hands it to a new `POST /blueprint-upload` endpoint along with a `blueprintScope` context (set by the themes/plugins/users/config/pages routes so `self@:` resolves to the right owner). Requires grav-plugin-api ≥ beta.13.
    * File-field removal is now tied to the **save** commit instead of firing a `DELETE` on every ✕ click. Eager deletes left a confusing half-state: the file was gone from disk immediately, but the YAML still referenced it until save — so a reload (or a cancel) would show a broken entry whose image couldn't be retrieved. A new lightweight `formCommit` context lets `FileField` stage removed paths in a `pendingDeletes` set; the host route (themes/plugins/users/config) calls `formCommit.emit()` after a successful PATCH + fresh-config fetch, and only then does `FileField` fire the actual `DELETE /blueprint-upload` calls. Removing a file and then navigating away without saving leaves both the file and the reference intact, so reload restores the expected state.
    * Page-edit view no longer fires phantom `GET /pages//media` requests (404) before the home-alias redirect resolves. When the user lands on `/pages/edit/` without an explicit slug, `route` starts as `/`; the host page resolves the home alias and replaces the URL with the structural route (e.g. `/home`), but `PageMedia` was eagerly calling `getPageMedia('/')` in `onMount` during that window — producing `/pages//media` which nginx collapses to `/pages/media` and FastRoute mis-matches. `PageMedia` now has a `routeReady` guard (`route !== '' && route !== '/'`); the initial load is skipped until `route` flips to a concrete path, and a reactive `$effect` re-fires the load the moment it does. `getPageMedia` / `uploadPageMedia` / `deletePageMedia` also refuse empty routes at the API-client layer as a defense-in-depth.

# v2.0.0-beta.12
## 04/24/2026

1. [](#improved)
    * Require Login version `3.8.2` for security fixes

# v2.0.0-beta.11
## 04/22/2026

1. [](#new)
    * **Environment selector dropdown** in the app shell. The environment badge is now an interactive dropdown that lists every writable target: `Default` (base `user/config/`) and each existing `user/env/*` or legacy `user/<host>/` folder. Switching the selection persists per-user via scoped localStorage and drives a new `X-Config-Environment` header on every API request, so config/plugin/theme saves land exactly where you chose — no more invisible env folder auto-created from the hostname. The dropdown also offers an inline **Create env "<current-host>"…** action that calls `POST /system/environments` to create a fresh `user/env/<name>/config/` folder and switches the target to it; envs carrying existing overrides are flagged. Pairs with server-side differential saves: with an env selected, only the keys that differ from the effective base layer are written to that env's file, matching the hand-edit workflow instead of forking full copies. Requires grav-plugin-api ≥ beta.12.
2. [](#bugfix)
    * Config/plugin/user/page/flex-object detail pages no longer toast `"<Resource> changed elsewhere — save to overwrite or reload"` after every save. The server's `X-Invalidates` response header fires the matching subscriber inside the same `PATCH` that initiated the save, before `handleSave` has had a chance to clear `hasChanges`. The subscriber saw itself as dirty and showed the "out of sync" toast on top of the success toast. All five detail-route subscribers now pass `dirtyGuard: () => saving` (plus `autoSave.saving` where applicable) so the handler skips while our own save is in flight — other tabs saving the same resource still trigger the toast as intended.
    * Custom field web components rendered outside the page-edit route now have access to the active content language via `window.__GRAV_CONTENT_LANG`. Previously this global was only populated on `/pages/edit/*`, which pushed plugins (seo-magic et al.) into reaching for `localStorage.getItem('grav_admin_content_lang')` directly. That key is site-scoped (`grav_admin_content_lang::/<basePath>`) on sub-path installs, so the read returned empty and multi-language checks silently fell back to the default locale. `CustomFieldWrapper` now mirrors `contentLang.activeLang` to the global reactively, so every custom field site (plugin configs, themes, user profiles, flex-objects) gets the live value and picks up language switches without a page reload. Requires grav-plugin-seo-magic ≥ 7.0.0 (and similar) to consume.

# v2.0.0-beta.10
## 04/21/2026

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
