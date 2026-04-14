# v2.0.0-alpha.1
## 04/14/2026

1. [](#new)
    * Initial alpha release
    * SvelteKit single-page application served from `app/` directory
    * PHP wrapper serves static assets and SPA shell, injects runtime config via `window.__GRAV_CONFIG__`
    * Route configurable via `plugins.admin2.route` (defaults to `/admin2`)
    * All data operations routed through the Grav API plugin
    * `bin/build.sh` for building the sibling `grav-admin-next` SvelteKit project into this plugin
