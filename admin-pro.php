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
     * Serve the SPA index.html with injected Grav config.
     */
    public function onPagesInitialized(): void
    {
        $appDir = __DIR__ . '/app';
        $indexFile = $appDir . '/index.html';

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

        // Read the SvelteKit index.html and rewrite asset paths
        $html = file_get_contents($indexFile);

        // Rewrite asset paths: /_app/ → /user/plugins/admin-pro/app/_app/
        $appBaseUrl = '/' . ltrim($this->grav['locator']->findResource('plugin://admin-pro/app', false), '/');
        $html = str_replace('="/_app/', '="' . $appBaseUrl . '/_app/', $html);
        $html = str_replace('("/_app/', '("' . $appBaseUrl . '/_app/', $html);

        // Inject config script right after <head>
        $html = str_replace('<head>', '<head>' . "\n    " . $configScript, $html);

        // Send it
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }
}
