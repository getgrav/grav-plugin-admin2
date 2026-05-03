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
 * The SvelteKit build bakes a placeholder base path into its assets; on
 * first request per-site, the plugin materializes a site-local copy of
 * the bundle into cache/ with the real base path substituted in. The
 * source app/ directory is never mutated, which lets the same plugin be
 * symlinked into many sites (each with its own rootUrl + route) without
 * them trampling each other.
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
     * The full URL path from the webserver root — the Grav site's rootUrl
     * plus $base. This is what gets baked into SvelteKit's paths.base and
     * therefore into every asset/link URL in the materialized bundle.
     * Example: '/admin2' on a root-hosted site, '/grav-api/admin2' when
     * Grav is mounted at /grav-api/.
     */
    protected string $assetsBase = '';

    /** @var string Absolute path to the materialized app/ inside this site's cache */
    protected string $servedAppDir = '';

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

        $user = $event['user'] ?? null;
        if (!$user) {
            return;
        }
        $isManager = (bool) (
            $user->get('access.api.super')
            ?? $user->get('access.admin.super')
            ?? $user->get('access.api.users.write')
        );
        if (!$isManager) {
            return;
        }

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

        $fields = $event['fields'];
        $event['fields'] = $this->insertFieldAfter($fields, 'title', $stateField);
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
     * Early setup — detect if the current request is for our route.
     * Static assets are served immediately here, before Grav does any
     * heavy lifting (pages, twig, etc.).
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
        $this->assetsBase = $rootPath . $this->base;

        // Grav core strips known "page" extensions (html, json, xml, rss…)
        // from $uri->route(), per system.pages.types. SvelteKit polls
        // /_app/version.json and other plugins may host static .json/.xml
        // assets — reattach any stripped extension so static-asset matching
        // sees the real filename.
        $currentRoute = $uri->route();
        $stripped = $uri->extension();
        if ($stripped) {
            $currentRoute .= '.' . $stripped;
        }

        if ($currentRoute === $this->base || str_starts_with($currentRoute, $this->base . '/')) {
            $this->isAdmin2Route = true;

            // Ensure this site has an up-to-date materialized copy of the
            // SPA with its own base path substituted in. Cheap on the hot
            // path (one small JSON read + mtime compare).
            $this->ensureMaterialized();

            // Serve static assets immediately — exit before Grav loads anything else
            $subPath = substr($currentRoute, strlen($this->base));
            if (
                str_starts_with($subPath, '/_app/')
                || str_starts_with($subPath, '/fonts/')
                || $subPath === '/robots.txt'
                || $subPath === '/favicon.ico'
            ) {
                $this->serveStaticAsset($subPath);
                // serveStaticAsset calls exit, so we never reach here
            }
        }
    }

    /**
     * Build (or refresh) this site's materialized copy of the SPA bundle
     * in cache://admin2/app/. The source at __DIR__/app/ is never modified,
     * so the same plugin directory can be symlinked into many sites.
     *
     * Re-materialization happens when:
     *   - No manifest exists yet
     *   - The configured assetsBase has changed (route or site root moved)
     *   - The source bundle is newer than the materialized copy
     */
    private function ensureMaterialized(): void
    {
        $sourceDir = __DIR__ . '/app';
        $sourceIndex = $sourceDir . '/index.html';

        if (!is_file($sourceIndex)) {
            // App not built; serveSpaShell() handles the error path.
            return;
        }

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $cacheRoot = $locator->findResource('cache://', true);
        if (!$cacheRoot) {
            // Nowhere safe to materialize; leave $servedAppDir empty so
            // serveStaticAsset / serveSpaShell return errors.
            return;
        }

        $cacheDir = $cacheRoot . '/admin2';
        $this->servedAppDir = $cacheDir . '/app';
        $manifestPath = $cacheDir . '/manifest.json';

        $sourceMtime = filemtime($sourceIndex) ?: 0;

        $manifest = null;
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        $upToDate = $manifest
            && is_dir($this->servedAppDir)
            && ($manifest['assetsBase'] ?? null) === $this->assetsBase
            && ($manifest['sourceMtime'] ?? null) === $sourceMtime;

        if ($upToDate) {
            return;
        }

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return;
        }

        // Materialize into a temp dir, then atomically swap in. This
        // avoids half-written files being served during the copy.
        $stagingDir = $cacheDir . '/app.staging.' . bin2hex(random_bytes(4));

        try {
            $this->materializeApp($sourceDir, $stagingDir, self::BASE_PLACEHOLDER, $this->assetsBase);
        } catch (\Throwable $e) {
            $this->rrmdir($stagingDir);
            return;
        }

        // Swap: remove the old materialized dir, rename staging into place.
        // Not fully atomic (two operations), but the window is narrow and
        // the worst case is a transient 404 on one asset.
        if (is_dir($this->servedAppDir)) {
            $this->rrmdir($this->servedAppDir);
        }
        if (!@rename($stagingDir, $this->servedAppDir)) {
            $this->rrmdir($stagingDir);
            return;
        }

        file_put_contents($manifestPath, json_encode([
            'assetsBase' => $this->assetsBase,
            'sourceMtime' => $sourceMtime,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Copy $sourceDir to $targetDir, substituting $from for $to in every
     * text-bearing file. Binary assets (fonts, images) are copied verbatim.
     */
    private function materializeApp(string $sourceDir, string $targetDir, string $from, string $to): void
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Could not create {$targetDir}");
        }

        $textExtensions = ['js', 'mjs', 'css', 'html', 'json', 'svg', 'map'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($sourceDir));
            $target = $targetDir . $relative;

            if ($file->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("Could not create {$target}");
                }
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (in_array($ext, $textExtensions, true)) {
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    throw new \RuntimeException("Could not read {$file->getPathname()}");
                }
                if ($from !== $to && str_contains($content, $from)) {
                    $content = str_replace($from, $to, $content);
                }
                file_put_contents($target, $content);
            } else {
                if (!@copy($file->getPathname(), $target)) {
                    throw new \RuntimeException("Could not copy {$file->getPathname()}");
                }
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
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
        // the site root itself. $this->assetsBase already includes the root, so
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
     * Serve a static file from the materialized app/ directory and exit
     * immediately. Called during setup() to bypass all Grav processing.
     */
    private function serveStaticAsset(string $subPath): void
    {
        if ($this->servedAppDir === '') {
            http_response_code(500);
            exit;
        }

        $filePath = $this->servedAppDir . $subPath;

        if (!file_exists($filePath) || !is_file($filePath)) {
            http_response_code(404);
            exit;
        }

        // Security: ensure resolved path is within the materialized app dir
        $realPath = realpath($filePath);
        $appRealPath = realpath($this->servedAppDir);
        if (!$realPath || !$appRealPath || !str_starts_with($realPath, $appRealPath)) {
            http_response_code(403);
            exit;
        }

        $mime = match (pathinfo($filePath, PATHINFO_EXTENSION)) {
            'js' => 'text/javascript',
            'mjs' => 'text/javascript',
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            default => mime_content_type($filePath) ?: 'application/octet-stream',
        };

        // Immutable assets (hashed filenames) get aggressive caching
        $cache = str_contains($subPath, '/immutable/')
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: ' . $cache);
        readfile($filePath);
        exit;
    }

    /**
     * Serve the SPA index.html (from the materialized copy) with injected
     * Grav config.
     */
    private function serveSpaShell(): void
    {
        $indexFile = $this->servedAppDir !== '' ? $this->servedAppDir . '/index.html' : '';

        if ($indexFile === '' || !file_exists($indexFile)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Admin2: app not available. Ensure the plugin is built (bin/build.sh) and cache/ is writable.';
            exit;
        }

        // Build the config to inject
        $apiRoute = $this->config->get('plugins.api.route', '/api');
        $apiVersion = $this->config->get('plugins.api.version_prefix', 'v1');

        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];
        $serverUrl = rtrim($uri->rootUrl(true), '/');

        $config = json_encode([
            'serverUrl' => $serverUrl,
            'apiPrefix' => '/' . trim($apiRoute, '/') . '/' . trim($apiVersion, '/'),
            'basePath' => $this->assetsBase,
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

        $html = file_get_contents($indexFile);

        // Inject config script right after <head>
        $html = str_replace('<head>', '<head>' . "\n    " . $configScript, $html);

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }
}
