<?php

declare(strict_types=1);

namespace RC\Portal\Http;

use RC\Portal\Module\EmbeddedModuleInterface;

/**
 * Immutable request context exposed to the presentation layer.
 *
 * RC Portal resolves routing and authorization. The active theme consumes
 * this context and remains solely responsible for HTML/templates/assets.
 */
final class RouteContext
{
    public function __construct(
        public readonly string $route,
        public readonly int $status,
        public readonly ?EmbeddedModuleInterface $module = null,
        public readonly ?string $message = null,
        public readonly ?string $pageLabel = null,
        public readonly ?string $pageHtml = null
    ) {
    }

    public function isDashboard(): bool
    {
        return $this->route === 'dashboard' && $this->status === 200;
    }

    public function isLogin(): bool
    {
        return $this->route === 'login';
    }

    public function isAllowed(): bool
    {
        return $this->status === 200;
    }

    /**
     * True when this route was resolved through RC Core's `UiRegistry`
     * (a module dashboard or child page registered via `rc_register_ui_page()`)
     * rather than through the legacy `EmbeddedModuleInterface` placeholder.
     */
    public function hasUiPage(): bool
    {
        return $this->pageHtml !== null;
    }
}
