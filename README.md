# Grav Admin2 Plugin

**Admin2** is a modern, redesigned administration panel for [Grav CMS](https://github.com/getgrav/grav). It is a ground-up rewrite of the classic admin plugin, built as a [SvelteKit](https://svelte.dev/docs/kit/introduction) single-page application that communicates with Grav exclusively through the [Grav API plugin](https://github.com/getgrav/grav-plugin-api).

> **Status: Alpha.** Admin2 is under active development. It is not yet a drop-in replacement for the standard admin plugin and is intended for evaluation and contributor use.

## Architecture

Admin2 is intentionally decoupled from Grav's PHP render pipeline:

- **This plugin (`grav-plugin-admin2`)** — a thin PHP wrapper that detects the configured route, serves static SPA assets (with appropriate cache headers), and returns the SPA shell for all other sub-routes. It injects a small `window.__GRAV_CONFIG__` object so the SPA knows the server URL, API prefix, and base path.
- **Grav API plugin (`grav-plugin-api`)** — provides the JSON HTTP API that the SPA consumes for every data operation (pages, config, users, media, plugins, themes, tools, etc.).
- **SvelteKit source (`grav-admin-next`)** — the SPA source lives in a separate repository and is built into this plugin's `app/` directory.

This means the PHP footprint here is small and the UI ships as a pre-built static bundle.

## Highlights

- **Pages** — tree, list, and Miller (columns) browsers; drag-reorder; per-page draft / visible indicators (icon-color); home page works correctly throughout; copy / move / delete; multi-language editing with per-translation status; D-pad page navigator on the edit screen.
- **Customizable dashboard** — 4-column responsive grid where every widget can be reordered, resized, hidden, or restored. Saved per-user, plus a "Save as site default" action for super-admins. Three presets (Default, Minimal, Compact) and an `xl` full-width size. Plugin-contributed widgets appear automatically via the API's `onApiDashboardWidgets` event.
- **Real-time collaborative editing** (opt-in via Settings → Editing) — multiple users edit the same page simultaneously with named cursors, presence avatars in the topbar, and CRDT-merged form fields. Capability-driven transport: prefers Mercure (sub-100ms SSE) when the server advertises a hub, falls back to 1-second short-poll. Requires grav-plugin-sync (and optionally grav-plugin-sync-mercure).
- **Environment selector** — switch the write target between `Default` (`user/config/`) and any `user/env/<name>/` folder; create new env folders inline. Pairs with server-side differential saves so env files only carry keys that differ from the base.
- **Customizable theme** — light / dark / system mode, accent color picker (presets + custom HSV), font picker (5 self-hosted variable typefaces).
- **First-run setup** — guided creation of the initial super-admin with a live password strength meter and configurable rules (driven by `system.pwd_regex` / `system.pwd_rules`).
- **Notifications** (v2 schema) — structured payloads with rich cards for promos and inline-markdown items for everything else; `Refresh` force-refetches the cache.
- **Plugin / theme management** — install, update, remove with automatic dependency installation; per-package toasts; license-aware premium installs.
- **Tools** — backups, cache, system info, scheduler, logs, plus extensible per-plugin pages.

## Requirements

- Grav `>= 2.0.0`
- [API plugin](https://github.com/getgrav/grav-plugin-api) `>= 1.0.0`
- Login plugin `>= 3.8.2`
- A user account with admin privileges (the `login` plugin handles authentication as with the classic admin)

**Optional** — for real-time collaborative editing:

- [Sync plugin](https://github.com/getgrav/grav-plugin-sync) `>= 1.0` (CRDT storage + presence)
- [Editor Pro plugin](https://github.com/getgrav/grav-plugin-editor-pro) `>= 2.0.1` (collab-aware WYSIWYM editor)
- [Sync Mercure plugin](https://github.com/getgrav/grav-plugin-sync-mercure) (optional, low-latency SSE transport)

## Installation

Admin2 is not yet available in GPM. Install manually alongside its dependency:

```
cd user/plugins
git clone https://github.com/getgrav/grav-plugin-api.git api
git clone https://github.com/getgrav/grav-plugin-admin2.git admin2
```

Then ensure both plugins are enabled in their respective config files (or via the classic admin).

## Configuration

Default config (`user/config/plugins/admin2.yaml`):

```yaml
enabled: true
route: /admin2
```

Override the route to anything you like (e.g. `/manager`) — the PHP wrapper and the pre-built SPA must agree on the base path. If you change the route, you must rebuild the SPA with a matching `ADMIN2_BASE` (see **Building from source** below) so the SvelteKit asset paths resolve correctly.

## Accessing Admin2

Point your browser at `http://yoursite.com/admin2` and log in with a user account that has `api.login` and `api.super` permissions. Note that Admin2 uses the `api.*` permission namespace (provided by the Grav API plugin), **not** the classic admin's `admin.*` namespace — accounts intended for Admin2 need explicit `api:` access.

If you do not yet have an admin user, create one via the `login` plugin CLI:

```
bin/plugin login new-user
```

Or create `user/accounts/<username>.yaml` manually:

```yaml
password: 'your-password'
email: 'you@example.com'
fullname: 'Your Name'
title: 'Site Administrator'
access:
  api:
    login: true
    super: true
  site:
    login: true
```

## Building from source

To modify the UI, clone the SvelteKit source as a sibling of this plugin:

```
cd /path/to/Projects/grav
git clone https://github.com/getgrav/grav-admin-next.git
```

Then from the plugin directory:

```
./bin/build.sh
```

This invokes `npm run build` in the SvelteKit project with `ADMIN2_BASE=/grav-api/admin2` and copies the output into `app/`. Override the base path when building for a different Grav root:

```
ADMIN2_BASE=/my-site/admin2 ./bin/build.sh
```

Or pass a custom path to the SvelteKit project:

```
./bin/build.sh /path/to/grav-admin-next
```

During SvelteKit development you can run `npm run dev:plugin` in `grav-admin-next` to continuously rebuild into this plugin's `app/` directory.

## Relationship to the classic admin

**Admin2 is the successor to `grav-plugin-admin` in Grav 2.0.** The classic admin plugin will not be supported on Grav 2.0 — Admin2 replaces it, and Grav 2.0 represents a clean break. We are moving on from the classic admin.

While it is technically possible to run both plugins at the same time during the alpha (each on its own route), doing so is not supported and should be reserved for short-term contributor testing only.

The two admins do not share UI code, events, or templates. Admin2 does not fire the `onAdmin*` event family that the classic admin exposes, because it does not run Grav's admin lifecycle — all data operations go through the API plugin instead. Plugins that integrate with the classic admin via Twig templates or admin-specific events will need to be updated to work with Admin2 and the API plugin.

## Contributing

Issues and pull requests are welcome at [getgrav/grav-plugin-admin2](https://github.com/getgrav/grav-plugin-admin2). The SvelteKit UI lives in [grav-admin-next](https://github.com/getgrav/grav-admin-next); please open PRs against the appropriate repo.

## License

MIT — see `LICENSE`.
