<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * Admin Pro — Modern administration panel for Grav CMS.
 *
 * Serves a pre-built SvelteKit SPA from the plugin's app/ directory.
 * The SPA communicates with the Grav API plugin for all data operations.
 */
class AdminProPlugin extends Plugin
{
    /** @var bool Whether the current request is for the Admin Pro route */
    protected bool $isAdminProRoute = false;

    /** @var string The base route (e.g. /admin-pro) */
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
     */
    public function setup(): void
    {
        $route = $this->config->get('plugins.admin-pro.route');
        if (!$route) {
            return;
        }

        $this->base = '/' . trim($route, '/');

        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];
        $currentRoute = $uri->route();

        if ($currentRoute === $this->base || str_starts_with($currentRoute, $this->base . '/')) {
            $this->isAdminProRoute = true;
        }
    }

    /**
     * If on our route, register the hook to serve the SPA.
     */
    public function onPluginsInitialized(): void
    {
        if (!$this->isAdminProRoute) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 1000],
        ]);
    }

    /**
     * Serve the SPA — either a static asset from app/ or the index.html shell.
     */
    public function onPagesInitialized(): void
    {
        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];
        $route = $uri->route();

        // Strip the base to get the sub-path (e.g. /admin-pro/_app/foo.js → /_app/foo.js)
        $subPath = substr($route, strlen($this->base));

        // Serve static assets from app/ directory
        if (str_starts_with($subPath, '/_app/')) {
            $this->serveStaticAsset($subPath);
            return;
        }

        // Otherwise serve the SPA shell
        $this->serveSpaShell();
    }

    /**
     * Serve a static file from the app/ directory.
     */
    private function serveStaticAsset(string $subPath): void
    {
        $filePath = __DIR__ . '/app' . $subPath;

        if (!file_exists($filePath) || !is_file($filePath)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Not found';
            exit;
        }

        // Security: ensure resolved path is within app/
        $realPath = realpath($filePath);
        $appRealPath = realpath(__DIR__ . '/app');
        if (!$realPath || !$appRealPath || !str_starts_with($realPath, $appRealPath)) {
            header('HTTP/1.1 403 Forbidden');
            exit;
        }

        $mime = match (pathinfo($filePath, PATHINFO_EXTENSION)) {
            'js' => 'text/javascript',
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => mime_content_type($filePath) ?: 'application/octet-stream',
        };

        // Immutable assets get long cache
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
            echo 'Admin Pro: app not built. Run bin/build.sh first.';
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
