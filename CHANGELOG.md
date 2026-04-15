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
