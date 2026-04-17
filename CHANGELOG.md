# v2.0.0-beta.7
## 04/17/2026

1. [](#new)
    * Symlink indicator in the Plugins and Themes listings — a small VS Code-style corner arrow (↳) renders to the right of each row (next to the Enabled / Disabled / Active pill) for any package installed via symlink, with a native `Symlinked` hover tooltip. Reads the new `is_symlink` field from grav-plugin-api beta.7.
    * Dashboard Updates card redesigned for hierarchy: prominent purple Grav-core callout (version arrow `v{current} → v{available}`, filled-purple **Upgrade Grav** button) sits above an amber package panel (per-row version chips, filled-amber **Update All** button). Distinct button colors differentiate core upgrades from routine package updates at a glance.
    * Grav core card degrades to an explanatory message ("installed via symlink — upgrade manually") and hides the upgrade button when `is_symlink` is reported by the API.
    * **View site** button restored in the top menubar (globe icon + "View site" + external-link glyph) — opens the Grav frontend (`auth.serverUrl`) in a new tab. Had been dropped during the auth transport refactor.
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
