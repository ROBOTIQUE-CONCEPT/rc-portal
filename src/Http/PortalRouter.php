<?php

declare(strict_types=1);

namespace RC\Portal\Http;

use RC\Portal\Core\CoreBridge;
use RC\Portal\Module\EmbeddedModuleInterface;
use RC\Portal\Module\ModuleCatalog;
use RC\Portal\Security\LoginProtection;
use Throwable;
use WP_Error;

/**
 * Owns Portal URLs, authentication and request resolution only.
 *
 * The application site is a private application: the dashboard lives at `/`
 * and business modules live directly at `/{module}/...`, without a Portal
 * base prefix. Rendering remains exclusively owned by the active theme.
 */
final class PortalRouter
{
    public const QUERY_VAR = 'rc_portal_route';

    /** RC Core `Surface::INTERNAL` — kept as a literal to avoid a hard dependency on Core classes. */
    private const CORE_UI_SURFACE = 'internal';

    private ?RouteContext $context = null;

    public function __construct(
        private readonly ModuleCatalog $modules,
        private readonly LoginProtection $loginProtection,
        private readonly ?CoreBridge $core = null
    )
    {
    }

    public function register(): void
    {
        add_action('init', [self::class, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'registerQueryVar']);
        add_action('template_redirect', [$this, 'prepareRequest'], 0);
    }

    public static function registerRewriteRules(): void
    {
        add_rewrite_rule('^login/?$', 'index.php?' . self::QUERY_VAR . '=login', 'top');
        add_rewrite_rule('^(.+?)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    /** @param list<string> $vars */
    public function registerQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function prepareRequest(): void
    {
        $route = $this->currentRoute();
        if ($route === '') {
            return;
        }

        if ($route === 'login') {
            $this->prepareLoginRequest();
            return;
        }

        if (! is_user_logged_in()) {
            $target = $this->currentAbsoluteUrl();
            wp_safe_redirect(add_query_arg('redirect_to', $target, self::loginUrl()));
            exit;
        }

        $this->context = $this->resolve($route);
        status_header($this->context->status);
        nocache_headers();

        do_action('rc_portal_route_resolved', $this->context);
    }

    public function context(): ?RouteContext
    {
        return $this->context;
    }

    public function isPortalRequest(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        return $this->currentRoute() !== '';
    }

    public function currentRoute(): string
    {
        $route = $this->normalizeRoute((string) get_query_var(self::QUERY_VAR));
        if ($route !== '') {
            return $route;
        }

        $path = $this->requestPath();
        if ($path === '/') {
            return 'dashboard';
        }

        // Defensive fallback before rewrite rules have been refreshed.
        return $this->normalizeRoute(trim($path, '/'));
    }

    private function prepareLoginRequest(): void
    {
        $redirect = $this->requestedRedirect();

        if (is_user_logged_in()) {
            wp_safe_redirect($redirect);
            exit;
        }

        $message = null;
        $status = 200;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rc_portal_action']) && $_POST['rc_portal_action'] === 'login') {
            $nonce = isset($_POST['rc_portal_login_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['rc_portal_login_nonce'])) : '';
            if (! wp_verify_nonce($nonce, 'rc_portal_login')) {
                $message = __('La demande de connexion a expiré. Veuillez réessayer.', 'rc-portal');
                $status = 403;
            } else {
                $turnstile = $this->loginProtection->verify();
                if (! $turnstile['success']) {
                    $message = $turnstile['message'];
                    $status = 403;
                } else {
                    $credentials = [
                        'user_login' => isset($_POST['log']) ? sanitize_user(wp_unslash((string) $_POST['log'])) : '',
                        'user_password' => isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '',
                        'remember' => ! empty($_POST['rememberme']),
                    ];

                    $user = wp_signon($credentials, is_ssl());
                    if ($user instanceof WP_Error) {
                        $message = wp_strip_all_tags($user->get_error_message());
                        $status = 401;
                    } else {
                        wp_safe_redirect($redirect);
                        exit;
                    }
                }
            }
        }

        $this->context = new RouteContext('login', $status, null, $message);
        status_header($status);
        nocache_headers();
        do_action('rc_portal_route_resolved', $this->context);
    }

    private function resolve(string $route): RouteContext
    {
        if ($route === 'dashboard') {
            return new RouteContext('dashboard', 200);
        }

        $uiPage = $this->resolveUiPage($route);
        if ($uiPage !== null) {
            return $uiPage;
        }

        $segments = explode('/', $route);
        $moduleId = sanitize_key((string) ($segments[0] ?? ''));
        $module = $this->modules->get($moduleId);
        if (! $module instanceof EmbeddedModuleInterface) {
            return new RouteContext($route, 404);
        }

        $descriptor = $module->descriptor();
        if (! current_user_can($descriptor->requiredCapability)) {
            return new RouteContext($route, 403, $module);
        }

        return new RouteContext($route, 200, $module);
    }

    /**
     * Try to resolve the route through RC Core's `UiRegistry` (Internal
     * surface) before falling back to the legacy `ModuleCatalog` lookup.
     *
     * Modules contribute pages by calling `rc_register_ui_page()` from their
     * `register()` method; this is what gives each module a dashboard on its
     * root route plus child pages, without Portal knowing anything about a
     * module's own presentation logic. `UiRegistry::match()` is called with
     * `$checkCapability = false` so that a route which exists but is not
     * granted to the current user resolves to 403 (matching the existing
     * `ModuleCatalog` two-step read-descriptor-then-check-capability
     * pattern) rather than a plain 404.
     *
     * Returns null (never throws) whenever Core is unavailable, incompatible,
     * or no page is registered for the route, so the caller transparently
     * falls back to the legacy resolution path.
     */
    private function resolveUiPage(string $route): ?RouteContext
    {
        if (! function_exists('rc_core')) {
            return null;
        }

        try {
            $core = rc_core();
            if (! is_object($core) || ! method_exists($core, 'ui')) {
                return null;
            }

            $ui = $core->ui();
            if (! is_object($ui) || ! method_exists($ui, 'match')) {
                return null;
            }

            $match = $ui->match(self::CORE_UI_SURFACE, $route, wp_get_current_user(), false);
            if (! is_object($match) || ! isset($match->page) || ! is_object($match->page)) {
                return null;
            }

            $page = $match->page;
            if (! isset($page->capability, $page->route, $page->label) || ! method_exists($page, 'render')) {
                return null;
            }

            if (! current_user_can($page->capability)) {
                return new RouteContext($route, 403, null, null, $page->label);
            }

            $contextClass = '\\WPRC\\Core\\UI\\PageContext';
            if (! class_exists($contextClass)) {
                return null;
            }

            $pageContext = new $contextClass(
                wp_get_current_user(),
                self::CORE_UI_SURFACE,
                $page->route,
                (string) ($match->remainder ?? '')
            );

            $html = $page->render($pageContext);
            if (! is_string($html)) {
                return null;
            }

            return new RouteContext($route, 200, null, null, $page->label, $html);
        } catch (Throwable $exception) {
            $this->core?->warning(
                'Échec de résolution d’une page RC Core UI Registry.',
                'ui_page_resolution_failed',
                ['route' => $route, 'error' => $exception->getMessage()]
            );
            return null;
        }
    }

    /**
     * Navigation entries contributed by modules through RC Core's
     * `UiRegistry` (Internal surface), grouped by module root route so the
     * theme can render child pages under each module in the sidebar.
     *
     * Never throws: an unavailable/incompatible Core, or no registered
     * pages, simply yields an empty array.
     *
     * @return array<string, list<array{route:string,label:string}>>
     */
    public function navigationChildren(): array
    {
        if (! function_exists('rc_core')) {
            return [];
        }

        try {
            $core = rc_core();
            if (! is_object($core) || ! method_exists($core, 'ui')) {
                return [];
            }

            $ui = $core->ui();
            if (! is_object($ui) || ! method_exists($ui, 'forSurface')) {
                return [];
            }

            $pages = $ui->forSurface(self::CORE_UI_SURFACE, wp_get_current_user(), true);
            if (! is_array($pages)) {
                return [];
            }

            $children = [];
            foreach ($pages as $page) {
                if (! is_object($page) || ! isset($page->navigationParent, $page->route, $page->label) || $page->navigationParent === null) {
                    continue;
                }
                $children[$page->navigationParent][] = [
                    'route' => (string) $page->route,
                    'label' => (string) $page->label,
                ];
            }

            return $children;
        } catch (Throwable $exception) {
            $this->core?->warning(
                'Échec de lecture de la navigation RC Core UI Registry.',
                'ui_navigation_failed',
                ['error' => $exception->getMessage()]
            );
            return [];
        }
    }

    /**
     * Modules to list in the sidebar/dashboard, with the navigation URL and
     * accessible child pages already resolved.
     *
     * Visibility is recursive rather than all-or-nothing: a module whose own
     * root page ("read") capability is off but that has at least one
     * accessible child page still appears — as a group containing only the
     * child pages the user can actually reach — instead of disappearing
     * entirely. A module is hidden only when neither its root page nor any
     * of its child pages are granted. A module that has not (yet) registered
     * any page through Core's `UiRegistry` falls back to the legacy
     * `ModuleDescriptor::requiredCapability` all-or-nothing check, so nothing
     * already working regresses while later modules migrate.
     *
     * @param list<EmbeddedModuleInterface> $modules
     * @return array<string, array{
     *     id: string,
     *     label: string,
     *     icon: string,
     *     description: string,
     *     version: string,
     *     url: string,
     *     children: list<array{route: string, label: string}>
     * }>
     */
    public function visibleModuleNavigation(array $modules): array
    {
        $rootPages = [];
        $grantedRoots = [];
        $grantedChildren = [];

        if (function_exists('rc_core')) {
            try {
                $core = rc_core();
                if (is_object($core) && method_exists($core, 'ui')) {
                    $ui = $core->ui();

                    if (is_object($ui) && method_exists($ui, 'all')) {
                        $all = $ui->all();
                        foreach (($all[self::CORE_UI_SURFACE] ?? []) as $page) {
                            if (is_object($page) && isset($page->navigationParent, $page->module, $page->route) && $page->navigationParent === null) {
                                $rootPages[(string) $page->module] = (string) $page->route;
                            }
                        }
                    }

                    if (is_object($ui) && method_exists($ui, 'forSurface')) {
                        $granted = $ui->forSurface(self::CORE_UI_SURFACE, wp_get_current_user(), true);
                        if (is_array($granted)) {
                            foreach ($granted as $page) {
                                if (! is_object($page) || ! isset($page->navigationParent, $page->module, $page->route, $page->label)) {
                                    continue;
                                }
                                if ($page->navigationParent === null) {
                                    $grantedRoots[(string) $page->module] = true;
                                } else {
                                    $grantedChildren[(string) $page->navigationParent][] = [
                                        'route' => (string) $page->route,
                                        'label' => (string) $page->label,
                                    ];
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $exception) {
                $this->core?->warning(
                    'Échec de calcul de la visibilité des modules RC Core UI Registry.',
                    'ui_module_navigation_failed',
                    ['error' => $exception->getMessage()]
                );
            }
        }

        $navigation = [];
        foreach ($modules as $module) {
            $descriptor = $module->descriptor();
            $id = sanitize_key($descriptor->id);

            if (! isset($rootPages[$id])) {
                // Legacy module: no UiRegistry page registered at all yet.
                if (! current_user_can($descriptor->requiredCapability)) {
                    continue;
                }
                $navigation[$id] = [
                    'id' => $id,
                    'label' => $descriptor->label,
                    'icon' => $descriptor->icon,
                    'description' => $descriptor->description,
                    'version' => $descriptor->version,
                    'url' => self::moduleUrl($id),
                    'children' => [],
                ];
                continue;
            }

            $rootRoute = $rootPages[$id];
            $rootGranted = isset($grantedRoots[$id]);
            $children = $grantedChildren[$rootRoute] ?? [];

            if (! $rootGranted && $children === []) {
                continue;
            }

            $navigation[$id] = [
                'id' => $id,
                'label' => $descriptor->label,
                'icon' => $descriptor->icon,
                'description' => $descriptor->description,
                'version' => $descriptor->version,
                'url' => $rootGranted ? self::routeUrl($rootRoute) : self::routeUrl($children[0]['route']),
                'children' => $children,
            ];
        }

        return $navigation;
    }

    /** Deprecated compatibility shim: Portal no longer has a public base slug. */
    public static function baseSlug(): string
    {
        return '';
    }

    public static function homeUrl(): string
    {
        return home_url('/');
    }

    public static function loginUrl(): string
    {
        return home_url('/login/');
    }

    public static function moduleUrl(string $module_id): string
    {
        return home_url('/' . sanitize_key($module_id) . '/');
    }

    public static function routeUrl(string $route): string
    {
        $route = trim($route, '/');
        return $route === '' ? self::homeUrl() : home_url('/' . $route . '/');
    }

    private function requestedRedirect(): string
    {
        $requested = isset($_REQUEST['redirect_to']) ? rawurldecode((string) wp_unslash($_REQUEST['redirect_to'])) : '';
        return wp_validate_redirect($requested, self::homeUrl());
    }

    private function currentAbsoluteUrl(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return home_url($uri);
    }

    private function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        return '/' . ltrim($path, '/');
    }

    private function normalizeRoute(string $route): string
    {
        $route = trim($route, '/');
        if ($route === '') {
            return '';
        }

        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => sanitize_title($segment),
            explode('/', $route)
        )));

        return implode('/', $segments);
    }
}
