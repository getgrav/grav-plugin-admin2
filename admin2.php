<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;

/**
 * Admin2 — Modern administration panel for Grav CMS.
 *
 * Serves a pre-built SvelteKit SPA from the plugin's app/ directory.
 * The SPA communicates with the Grav API plugin for all data operations.
 *
 * The SvelteKit build bakes a single placeholder (`__GRAV_ADMIN2_BASE__`)
 * into index.html's entry-chunk preload links and into one runtime chunk
 * as the fallback for `globalThis.__sveltekit_<nonce>?.base`. On every
 * shell request, admin2.php substitutes that placeholder in the served
 * HTML and injects a `<script>` that sets the runtime global with two
 * separate per-site values:
 *
 *   - `base`   = the configured admin route (`/admin`) — used for in-app
 *                navigation and history.
 *   - `assets` = the same admin route — used for the SvelteKit version
 *                poll, which we intercept here in PHP because Grav's stock
 *                .htaccess blocks `user/*.json`.
 *
 * Every other byte (chunks, CSS, fonts, immutable assets) lives on disk
 * under `user/plugins/admin2/app/_app/...` and is served directly by the
 * webserver — no PHP, no materialization, no per-site copies. A single
 * plugin install can be symlinked into many sites with different rootUrls
 * or routes without them trampling each other.
 */
class Admin2Plugin extends Plugin
{
    /**
     * Token that the SvelteKit build uses as its `kit.paths.base`. Defined
     * in svelte.config.js — keep these in sync.
     */
    private const BASE_PLACEHOLDER = '/__GRAV_ADMIN2_BASE__';

    /** @var bool Whether the current request is for the Admin2 route */
    protected bool $isAdmin2Route = false;

    /**
     * The configured route, route-local (matches $uri->route() output).
     * Example: '/admin2' or '/admin'.
     */
    protected string $base = '';

    /**
     * The full URL path from the webserver root to the admin route — the
     * Grav site's rootUrl plus $base. Used as both the in-app route base
     * and the version-poll URL prefix in the injected runtime global.
     * Example: '/admin' on a root-hosted site, '/grav-api/admin' when
     * Grav is mounted at /grav-api/.
     */
    protected string $routeBase = '';

    /**
     * The full URL path from the webserver root to the on-disk bundle.
     * Substituted into index.html so chunk preload links resolve to real
     * files that the webserver can serve directly. Example:
     * '/user/plugins/admin2/app' on root, '/grav-api/user/plugins/admin2/app'
     * in a subfolder install.
     */
    protected string $assetsPath = '';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['setup', 100000],
                ['onPluginsInitialized', 1001],
            ],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
        ];
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }

    /**
     * Inject admin-next-only fields into resolved blueprints.
     *
     * Currently used to add a `state` (account-enabled) toggle to the user
     * account blueprint, which Grav core's `account.yaml` doesn't carry —
     * admin-classic has no UI for the field either, so previously the only
     * way to disable a user was to hand-edit YAML. The toggle is gated on
     * `api.users.write`, since the underlying PATCH /users/{name} also
     * rejects non-managers writing to `state` (see grav-plugin-api
     * v1.0.0-beta.15).
     */
    public function onApiBlueprintResolved(\RocketTheme\Toolbox\Event\Event $event): void
    {
        if (($event['template'] ?? null) !== 'account') {
            return;
        }

        $fields = $event['fields'];

        // Core's account.yaml references admin-classic callables
        // (\Grav\Plugin\Admin\Admin::adminLanguages and ::contentEditor)
        // for the `language` and `content_editor` fields. On admin-next
        // sites where admin-classic isn't installed, the API can't resolve
        // these and emits `data_options` for the client, which then 404s
        // against /data/resolve. Substitute admin-next-friendly options
        // here so the form is usable without admin-classic present.
        $fields = $this->rewriteAdminClassicDataOptions($fields);

        $user = $event['user'] ?? null;
        $isManager = $user ? (bool) (
            $user->get('access.api.super')
            ?? $user->get('access.admin.super')
            ?? $user->get('access.api.users.write')
        ) : false;

        if ($isManager) {
            // Note: injected fields bypass BlueprintController::serializeFields(),
            // so emit the post-serialization shape — `options` as an ordered
            // array of `{value, label}` objects rather than the YAML-blueprint
            // map form. Client-side i18n picks up `ADMIN_NEXT.*` labels via the
            // ICU.* dual-namespace lookup.
            $stateField = [
                'name'    => 'state',
                'type'    => 'select',
                'size'    => 'medium',
                'classes' => 'fancy',
                'label'   => 'ADMIN_NEXT.USERS.STATUS',
                'help'    => 'ADMIN_NEXT.USERS.STATUS_HELP',
                'default' => 'enabled',
                'options' => [
                    ['value' => 'enabled',  'label' => 'ADMIN_NEXT.ENABLED'],
                    ['value' => 'disabled', 'label' => 'ADMIN_NEXT.DISABLED'],
                ],
            ];
            $fields = $this->insertFieldAfter($fields, 'title', $stateField);
        }

        $event['fields'] = $fields;
    }

    /**
     * Recursively replace the legacy admin-classic data-options@ stand-ins
     * (which the API serializer left as `data_options` references because
     * the Admin class isn't loadable here) with concrete option lists.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function rewriteAdminClassicDataOptions(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (isset($field['fields']) && is_array($field['fields'])) {
                $field['fields'] = $this->rewriteAdminClassicDataOptions($field['fields']);
            }
            $directive = $field['data_options'] ?? null;
            if (is_string($directive) && $directive !== '') {
                $normalized = ltrim($directive, '\\');
                if ($normalized === 'Grav\\Plugin\\Admin\\Admin::adminLanguages') {
                    $field['options'] = $this->adminLanguageOptions();
                    unset($field['data_options']);
                } elseif ($normalized === 'Grav\\Plugin\\Admin\\Admin::contentEditor') {
                    $field['options'] = $this->contentEditorOptions();
                    unset($field['data_options']);
                }
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * Stand-in for \Grav\Plugin\Admin\Admin::adminLanguages when
     * admin-classic isn't installed. Admin-next currently only ships
     * English UI strings, so that's the only honest choice we can offer.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function adminLanguageOptions(): array
    {
        return [
            ['value' => 'en', 'label' => 'English'],
        ];
    }

    /**
     * Stand-in for \Grav\Plugin\Admin\Admin::contentEditor when
     * admin-classic isn't installed. Mirrors the legacy default list and
     * fires the same `onAdminListContentEditors` event so editor plugins
     * (editor-pro etc.) can register themselves the way they always have.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function contentEditorOptions(): array
    {
        $options = [
            'default'    => 'Default',
            'codemirror' => 'CodeMirror',
        ];
        $event = new \RocketTheme\Toolbox\Event\Event(['options' => &$options]);
        $this->grav->fireEvent('onAdminListContentEditors', $event);

        $out = [];
        foreach ($options as $value => $label) {
            $out[] = [
                'value' => (string) $value,
                'label' => is_string($label) ? $label : (string) $value,
            ];
        }
        return $out;
    }

    /**
     * Insert a field directly after a named sibling. Recurses into
     * container fields (`fields:` children) so the target is found
     * regardless of nesting depth. Returns the original list unchanged
     * if the anchor isn't present.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $newField
     * @return array<int, array<string, mixed>>
     */
    private function insertFieldAfter(array $fields, string $afterName, array $newField): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (isset($field['fields']) && is_array($field['fields'])) {
                $field['fields'] = $this->insertFieldAfter($field['fields'], $afterName, $newField);
            }
            $out[] = $field;
            if (($field['name'] ?? '') === $afterName) {
                $out[] = $newField;
            }
        }
        return $out;
    }

    /**
     * Early setup — detect if the current request targets our route.
     * Most chunk / CSS / font URLs the SPA emits point at the bundle on
     * disk (`/user/plugins/admin2/app/...`) and never reach PHP. The only
     * static asset the SPA hits via the admin route is `_app/version.json`,
     * which we serve here because Grav's stock .htaccess blocks
     * `user/*.json`.
     */
    public function setup(): void
    {
        $route = $this->config->get('plugins.admin2.route');
        if (!$route) {
            return;
        }

        $this->base = '/' . trim($route, '/');

        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];

        // Full path from webserver root. rootUrl(false) returns the path-only
        // portion of the Grav root (e.g. '/grav-api' or ''), never the host.
        $rootPath = rtrim($uri->rootUrl(false), '/');
        $this->routeBase = $rootPath . $this->base;

        // Derive the bundle's on-disk URL from the plugin's filesystem path,
        // not from a config knob: if a host relocates plugins (e.g. via a
        // custom stream override) the URL stays consistent with the files
        // Apache will actually be serving.
        $this->assetsPath = $rootPath . '/user/plugins/' . $this->name . '/app';

        // Grav core strips known "page" extensions (html, json, xml, rss…)
        // from $uri->route(), per system.pages.types. Reattach the
        // extension *only* when it was actually stripped (route doesn't
        // already end with it), so we recognize `_app/version.json` here.
        $currentRoute = $uri->route();
        $stripped = $uri->extension();
        if ($stripped && !str_ends_with($currentRoute, '.' . $stripped)) {
            $currentRoute .= '.' . $stripped;
        }

        if ($currentRoute === $this->base || str_starts_with($currentRoute, $this->base . '/')) {
            $this->isAdmin2Route = true;

            // version.json poll — serve from the plugin's app/ dir.
            $subPath = substr($currentRoute, strlen($this->base));
            if ($subPath === '/_app/version.json') {
                $this->serveVersionJson();
                // serveVersionJson exits
            }
        }
    }

    /**
     * Serve the SPA's version.json file. The SvelteKit runtime polls this
     * to detect when the bundle has been updated underneath the live SPA.
     * We pipe it through PHP because Grav's stock .htaccess blocks direct
     * access to `user/*.json`.
     */
    private function serveVersionJson(): void
    {
        $file = __DIR__ . '/app/_app/version.json';
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * If on our route (and not a static asset), register the hook to serve the SPA shell.
     */
    public function onPluginsInitialized(): void
    {
        // Bootstrap hijack — parity with admin-classic. If there are no user
        // accounts, send any frontend page request to the admin2 route so the
        // SPA's /auth/setup probe can take over and walk the visitor through
        // first-user creation. Without this, a site with admin2 installed but
        // no accounts would let the first random visitor who discovers the
        // admin route create the super user.
        //
        // Skip the API plugin's own route prefix — otherwise we'd intercept the
        // SPA's own /auth/setup probe and redirect it away.
        //
        // Pass the route-local base (e.g. '/admin') — Grav's redirect() prepends
        // the site root itself. $this->routeBase already includes the root, so
        // using it here would double-prefix on sites mounted in a subpath.
        if (!$this->isAdmin2Route && $this->base && !$this->isApiRoute() && !$this->anyUsersExist()) {
            $this->grav->redirect($this->base);
        }

        if (!$this->isAdmin2Route) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 1000],
        ]);
    }

    /**
     * Whether the current request targets the API plugin's route prefix.
     * The bootstrap hijack must not intercept these, or the SPA's own
     * /auth/setup probe would be redirected away from the API it needs.
     */
    private function isApiRoute(): bool
    {
        $apiRoute = rtrim((string) $this->config->get('plugins.api.route', '/api'), '/');
        if ($apiRoute === '') {
            return false;
        }
        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];
        $current = $uri->route();
        return $current === $apiRoute || str_starts_with($current, $apiRoute . '/');
    }

    /**
     * Check whether any user accounts exist. Mirrors Admin::doAnyUsersExist()
     * from admin-classic but is self-contained so admin2 does not depend on
     * admin-classic being installed.
     */
    private function anyUsersExist(): bool
    {
        $locator = $this->grav['locator'];
        $accountsDir = $locator->findResource('account://', true);
        if (!$accountsDir || !is_dir($accountsDir)) {
            return false;
        }

        foreach (glob($accountsDir . '/*.yaml') ?: [] as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serve the SPA shell for all non-asset routes.
     */
    public function onPagesInitialized(): void
    {
        $this->serveSpaShell();
    }

    /**
     * Serve the SPA index.html from the plugin's app/ directory with
     * per-site URLs substituted into the chunk preload links and a
     * runtime override for SvelteKit's `__sveltekit_<nonce>` global.
     */
    private function serveSpaShell(): void
    {
        $indexFile = __DIR__ . '/app/index.html';

        if (!file_exists($indexFile)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Admin2: app not available. Run `npm run build:plugin` from grav-admin-next.';
            exit;
        }

        $html = file_get_contents($indexFile);

        // The build emits one placeholder used in two contexts:
        //
        //   1. URL prefixes for chunk preload links and dynamic imports —
        //      these need to resolve to the real on-disk bundle location
        //      so the webserver serves each file directly without PHP.
        //   2. JS string literals inside the inline `__sveltekit_<nonce>`
        //      initializer — these need to be the admin *route* (so the
        //      SPA router stays mounted there, in-app navigation works,
        //      and version polling hits our setup() PHP path).
        //
        // Pattern (1): every URL-context occurrence is followed by `/`
        // (e.g. `/__GRAV_ADMIN2_BASE__/_app/...`). Pattern (2): the JS
        // literal is closed by `"` (e.g. `"/__GRAV_ADMIN2_BASE__"`).
        // Substitute each context with its correct value.
        $html = str_replace(
            ['"' . self::BASE_PLACEHOLDER . '"', self::BASE_PLACEHOLDER . '/'],
            ['"' . $this->routeBase . '"', $this->assetsPath . '/'],
            $html
        );

        // Inject our own per-site config that the SPA reads at boot.
        $apiRoute = $this->config->get('plugins.api.route', '/api');
        $apiVersion = $this->config->get('plugins.api.version_prefix', 'v1');

        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];
        $serverUrl = rtrim($uri->rootUrl(true), '/');

        $config = json_encode([
            'serverUrl' => $serverUrl,
            'apiPrefix' => '/' . trim($apiRoute, '/') . '/' . trim($apiVersion, '/'),
            'basePath' => $this->routeBase,
            'environment' => $uri->environment(),
            'grav' => [
                'version' => GRAV_VERSION,
            ],
            'admin' => [
                'name' => $this->getBlueprint()->get('name'),
                'version' => $this->getBlueprint()->get('version'),
            ],
        ], JSON_UNESCAPED_SLASHES);

        $configScript = "<script>window.__GRAV_CONFIG__ = {$config};</script>";
        $html = str_replace('<head>', '<head>' . "\n    " . $configScript, $html);

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

}
