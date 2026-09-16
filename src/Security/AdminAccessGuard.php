<?php

declare(strict_types=1);

namespace RC\Portal\Security;

use RC\Portal\Http\PortalRouter;

/** Prevents normal Portal users from entering wp-admin. */
final class AdminAccessGuard
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'redirectNonSuperAdmins'], 0);
        add_filter('login_redirect', [$this, 'filterLoginRedirect'], 20, 3);
        add_filter('show_admin_bar', [$this, 'filterAdminBar']);
    }

    public function redirectNonSuperAdmins(): void
    {
        if (! is_user_logged_in() || $this->canAccessWpAdmin() || $this->isAsyncAdminRequest()) {
            return;
        }

        wp_safe_redirect(PortalRouter::homeUrl());
        exit;
    }

    public function filterLoginRedirect(string $redirect_to, string $requested_redirect_to, $user): string
    {
        if ($user instanceof \WP_User && ! is_wp_error($user) && ! $this->canAccessWpAdmin($user->ID)) {
            return PortalRouter::homeUrl();
        }

        return $redirect_to;
    }

    public function filterAdminBar(bool $show): bool
    {
        return $this->canAccessWpAdmin() ? $show : false;
    }

    private function canAccessWpAdmin(?int $user_id = null): bool
    {
        $user_id ??= get_current_user_id();
        $allowed = $user_id > 0 && is_super_admin($user_id);

        /**
         * Allows an explicit installation policy override without hard-coding roles.
         */
        return (bool) apply_filters('rc_portal_can_access_wp_admin', $allowed, $user_id);
    }

    private function isAsyncAdminRequest(): bool
    {
        if (wp_doing_ajax()) {
            return true;
        }

        $script = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
        return in_array($script, ['admin-post.php', 'async-upload.php'], true);
    }
}
