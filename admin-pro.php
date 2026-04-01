<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;

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
     * Initialize if on our route — register the hooks needed to serve the SPA.
     */
    public function onPluginsInitialized(): void
    {
        if (!$this->isAdminProRoute) {
            return;
        }

        if (!$this->config->get('plugins.api.enabled')) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 1000],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    /**
     * Create a virtual page to render our SPA template.
     */
    public function onPagesInitialized(): void
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $pages->enablePages();

        $page = new Page();
        $page->init(new \SplFileInfo(__DIR__ . '/pages/admin-pro.md'));
        $page->slug('admin-pro');
        $page->template('admin-pro');

        unset($this->grav['page']);
        $this->grav['page'] = $page;
    }

    /**
     * Add our template directory so Twig can find admin-pro.html.twig.
     */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Inject configuration variables for the SPA.
     */
    public function onTwigSiteVariables(): void
    {
        $twig = $this->grav['twig'];

        $apiRoute = $this->config->get('plugins.api.route', '/api');
        $apiVersion = $this->config->get('plugins.api.version_prefix', 'v1');

        /** @var \Grav\Common\Uri $uri */
        $uri = $this->grav['uri'];
        $serverUrl = rtrim($uri->rootUrl(true), '/');

        $twig->twig_vars['admin_pro'] = [
            'server_url' => $serverUrl,
            'api_prefix' => '/' . trim($apiRoute, '/') . '/' . trim($apiVersion, '/'),
            'base_path' => $this->base,
            'environment' => $uri->environment(),
            'app_url' => $this->grav['locator']->findResource('plugin://admin-pro/app', false),
        ];
    }
}
