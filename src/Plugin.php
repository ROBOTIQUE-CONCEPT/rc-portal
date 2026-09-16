<?php

declare(strict_types=1);

namespace RC\Portal;

use RC\Portal\Core\CoreBridge;
use RC\Portal\Http\PortalRouter;
use RC\Portal\Module\ModuleCatalog;
use RC\Portal\Security\AdminAccessGuard;
use RC\Portal\Security\PrivateSiteGuard;
use RC\Portal\Security\LoginProtection;
use Throwable;

final class Plugin
{
    private static ?self $instance = null;

    private CoreBridge $core;
    private ModuleCatalog $modules;
    private PortalRouter $router;
    private LoginProtection $loginProtection;
    private bool $booted = false;

    private function __construct()
    {
        $this->core = new CoreBridge();
        $this->modules = new ModuleCatalog();
        $this->loginProtection = new LoginProtection($this->core);
        $this->router = new PortalRouter($this->modules, $this->loginProtection, $this->core);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function activate(): void
    {
        PortalRouter::registerRewriteRules();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        if (! $this->core->isCompatible()) {
            add_action('admin_notices', [$this, 'renderCoreNotice']);
            return;
        }

        try {
            $this->modules->loadFromDirectory(RC_PORTAL_DIR . 'modules');
            $this->modules->registerAll();
            $this->modules->bootAll();
        } catch (Throwable $exception) {
            $this->core->warning(
                'Échec du chargement des modules embarqués RC Portal.',
                'module_boot_failed',
                ['error' => $exception->getMessage()]
            );
            add_action(
                'admin_notices',
                static function () use ($exception): void {
                    if (! is_super_admin()) {
                        return;
                    }
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html('Échec du chargement des modules RC Portal : ' . $exception->getMessage())
                    );
                }
            );
            return;
        }

        (new AdminAccessGuard())->register();
        (new PrivateSiteGuard())->register();
        add_action('rc_portal_login_form', [$this->loginProtection, 'render']);
        $this->router->register();
        add_action('init', [$this, 'maybeRefreshRewriteRules'], 99);

        do_action('rc_portal_ready', $this);

        $this->core->info(
            'Runtime RC Portal initialisé.',
            'runtime_booted',
            [
                'version' => RC_PORTAL_VERSION,
                'module_count' => count($this->modules->all()),
                'ui_api' => RC_PORTAL_UI_API_VERSION,
            ]
        );
    }


    /**
     * Refreshes site-local rewrite rules once when the Portal runtime version changes.
     */
    public function maybeRefreshRewriteRules(): void
    {
        $stored = (string) get_option('rc_portal_rewrite_version', '');
        if ($stored === RC_PORTAL_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option('rc_portal_rewrite_version', RC_PORTAL_VERSION, false);
    }

    public function modules(): ModuleCatalog
    {
        return $this->modules;
    }

    public function core(): CoreBridge
    {
        return $this->core;
    }

    public function router(): PortalRouter
    {
        return $this->router;
    }

    /**
     * Renders only an infrastructure compatibility notice in wp-admin.
     * Application presentation belongs to the Portal theme.
     */
    public function renderCoreNotice(): void
    {
        if (! is_super_admin()) {
            return;
        }

        $installed = $this->core->version() ?? 'not active';
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                sprintf(
                    'RC Portal %s nécessite RC Core %s ou une version plus récente. Version actuelle : %s.',
                    RC_PORTAL_VERSION,
                    RC_PORTAL_MIN_CORE_VERSION,
                    $installed
                )
            )
        );
    }
}
