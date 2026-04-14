<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * Admin2 — Modern administration panel for Grav CMS.
 *
 * Serves a pre-built SvelteKit SPA from the plugin's app/ directory.
 * The SPA communicates with the Grav API plugin for all data operations.
 */
class Admin2Plugin extends Plugin
{
    /**
     * Token that the SvelteKit build uses as its `kit.paths.base`. The plugin
     * substitutes this for the configured route in the built assets the first
     * time a request comes in (and whenever the route changes). Defined in
     * svelte.config.js — keep these in sync.
     */
    private const BASE_PLACEHOLDER = '/__GRAV_ADMIN2_BASE__';

    /** @var bool Whether the current request is for the Admin2 route */
    protected bool $isAdmin2Route = false;

    /** @var string The base route (e.g. /admin2) */
    protected string $base = '';

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['setup', 100000],
                ['onPluginsInitialized', 1001],
            ],
        ];
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
        $currentRoute = $uri->route();

        if ($currentRoute === $this->base || str_starts_with($currentRoute, $this->base . '/')) {
            $this->isAdmin2Route = true;

            // Ensure the built assets have the configured route baked in.
            // Fast path when already applied — one small file read.
            $this->ensureBaseApplied();

            // Serve static assets immediately — exit before Grav loads anything else
            $subPath = substr($currentRoute, strlen($this->base));
            if (str_starts_with($subPath, '/_app/')) {
                $this->serveStaticAsset($subPath);
                // serveStaticAsset calls exit, so we never reach here
            }
        }
    }

    /**
     * Make sure the SvelteKit build in app/ has the currently-configured
     * route substituted in for the placeholder token. This is how the plugin
     * supports runtime-configurable routes without a rebuild.
     *
     * Called early in every admin2 request; steady-state cost is one file
     * read of index.html. When the route changes (or a fresh build is
     * dropped in by GPM) it walks the app/ tree and str_replaces the token
     * for the configured route.
     */
    private function ensureBaseApplied(): void
    {
        $appDir = __DIR__ . '/app';
        $indexPath = $appDir . '/index.html';
        $markerPath = $appDir . '/.base-applied';

        if (!is_file($indexPath)) {
            // App not built; serveSpaShell() handles the error path.
            return;
        }

        // Detect what's currently in the built assets by inspecting one
        // known file. This avoids trusting only the marker, which can be
        // stale after a plugin update overwrites app/ with fresh tokens.
        $indexContent = file_get_contents($indexPath);

        $needle = $this->base . '/_app/';
        if (str_contains($indexContent, $needle)) {
            // Already applied for this route.
            return;
        }

        // Work out what to substitute FROM. If the placeholder is still in
        // the file, the build is fresh; otherwise a previous route was
        // applied and the marker tells us which one.
        if (str_contains($indexContent, self::BASE_PLACEHOLDER . '/_app/')) {
            $from = self::BASE_PLACEHOLDER;
        } elseif (is_file($markerPath)) {
            $from = trim((string) file_get_contents($markerPath));
            if ($from === '' || !str_contains($indexContent, $from . '/_app/')) {
                // Marker is stale; bail rather than corrupt the bundle.
                return;
            }
        } else {
            // No placeholder and no marker — can't determine source state.
            return;
        }

        $this->substituteBase($appDir, $from, $this->base);
        file_put_contents($markerPath, $this->base);
    }

    /**
     * Recursively rewrite the base token across text-bearing files in app/.
     * Binary assets (fonts, images) are left alone.
     */
    private function substituteBase(string $dir, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $textExtensions = ['js', 'mjs', 'css', 'html', 'json', 'svg', 'map'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $textExtensions, true)) {
                continue;
            }

            $path = $file->getPathname();
            $content = file_get_contents($path);
            if ($content === false || !str_contains($content, $from)) {
                continue;
            }

            file_put_contents($path, str_replace($from, $to, $content));
        }
    }

    /**
     * If on our route (and not a static asset), register the hook to serve the SPA shell.
     */
    public function onPluginsInitialized(): void
    {
        if (!$this->isAdmin2Route) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 1000],
        ]);
    }

    /**
     * Serve the SPA shell for all non-asset routes.
     */
    public function onPagesInitialized(): void
    {
        $this->serveSpaShell();
    }

    /**
     * Serve a static file from the app/ directory and exit immediately.
     * Called during setup() to bypass all Grav processing.
     */
    private function serveStaticAsset(string $subPath): void
    {
        $filePath = __DIR__ . '/app' . $subPath;

        if (!file_exists($filePath) || !is_file($filePath)) {
            http_response_code(404);
            exit;
        }

        // Security: ensure resolved path is within app/
        $realPath = realpath($filePath);
        $appRealPath = realpath(__DIR__ . '/app');
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
     * Serve the SPA index.html with injected Grav config.
     */
    private function serveSpaShell(): void
    {
        $indexFile = __DIR__ . '/app/index.html';

        if (!file_exists($indexFile)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Admin2: app not built. Run bin/build.sh first.';
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
            'basePath' => $this->base,
            'environment' => $uri->environment(),
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
