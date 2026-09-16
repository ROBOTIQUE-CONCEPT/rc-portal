<?php

declare(strict_types=1);

namespace RC\Portal\Security;

use RC\Portal\Http\PortalRouter;
use WP_Error;

/**
 * Makes the application site private by default.
 *
 * Authentication remains entirely native to WordPress. Anonymous access is
 * limited to explicitly allow-listed technical endpoints (login/PWA today,
 * signed routes later). All business frontend and REST requests are private.
 */
final class PrivateSiteGuard
{
    /** @var list<string> */
    private const PUBLIC_PATHS = [
        '/login/',
        '/portal.webmanifest',
        '/portal-sw.js',
    ];

    public function register(): void
    {
        add_action('template_redirect', [$this, 'enforceFrontend'], -100);
        add_filter('rest_authentication_errors', [$this, 'enforceRestAuthentication'], 99);
        add_filter('wp_robots', [$this, 'filterRobots'], 100);
        add_action('send_headers', [$this, 'sendPrivateHeaders'], 100);
        add_filter('xmlrpc_enabled', '__return_false');
    }

    public function enforceFrontend(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if ($this->isPublicRequest()) {
            return;
        }

        if (! is_user_logged_in()) {
            $target = $this->currentAbsoluteUrl();
            $login = add_query_arg('redirect_to', $target, PortalRouter::loginUrl());
            wp_safe_redirect($login);
            exit;
        }

        // No CMS page is rendered on MY: Portal owns every frontend URL.
    }

    /**
     * @param WP_Error|null|true $result
     * @return WP_Error|null|true
     */
    public function enforceRestAuthentication($result)
    {
        if ($result instanceof WP_Error) {
            return $result;
        }

        if (is_user_logged_in()) {
            return $result;
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $allowed = (bool) apply_filters('rc_portal_allow_anonymous_rest_request', false, $requestUri);
        if ($allowed) {
            return $result;
        }

        return new WP_Error(
            'rc_portal_authentication_required',
            __('Une authentification est requise.', 'rc-portal'),
            ['status' => 401]
        );
    }

    /** @param array<string, bool|string> $robots */
    public function filterRobots(array $robots): array
    {
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        $robots['noarchive'] = true;
        unset($robots['index'], $robots['follow']);

        return $robots;
    }

    public function sendPrivateHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Robots-Tag: noindex, nofollow, noarchive', true);

        if (! $this->isStaticPwaEndpoint()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
        }
    }

    private function isPublicRequest(): bool
    {
        $path = $this->requestPath();
        $public = in_array($path, self::PUBLIC_PATHS, true);

        return (bool) apply_filters('rc_portal_is_public_request', $public, $path);
    }

    private function isStaticPwaEndpoint(): bool
    {
        return in_array($this->requestPath(), ['/portal.webmanifest', '/portal-sw.js'], true);
    }

    private function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $path = '/' . ltrim($path, '/');

        if ($path !== '/' && str_ends_with($path, '/')) {
            return $path;
        }

        if ($path !== '/' && ! str_contains(basename($path), '.')) {
            $path .= '/';
        }

        return $path;
    }

    private function currentAbsoluteUrl(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return home_url($uri);
    }
}
