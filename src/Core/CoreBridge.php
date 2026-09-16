<?php

declare(strict_types=1);

namespace RC\Portal\Core;

use Throwable;

/**
 * Thin compatibility boundary around the RC Core public runtime.
 *
 * Business modules must consume Core contracts directly. This class only
 * protects Portal bootstrap/observability from hard failures during upgrades.
 */
final class CoreBridge
{
    public function isAvailable(): bool
    {
        return function_exists('rc_core') && defined('RC_CORE_VERSION');
    }

    public function isCompatible(): bool
    {
        return $this->isAvailable()
            && version_compare((string) RC_CORE_VERSION, RC_PORTAL_MIN_CORE_VERSION, '>=');
    }

    public function version(): ?string
    {
        return defined('RC_CORE_VERSION') ? (string) RC_CORE_VERSION : null;
    }

    /**
     * Resolve the current application persona ('admin', 'internal', 'partner',
     * 'customer', 'external' or 'none') through RC Core's `PersonaResolver`.
     *
     * The Portal theme uses this to label the interface (e.g. "Espace
     * interne" vs "Espace client") without ever reasoning about capabilities
     * or role names itself. Never throws: an unavailable/incompatible Core,
     * or any unexpected shape, resolves to 'none'.
     */
    public function personaKey(?\WP_User $user = null): string
    {
        if (! $this->isAvailable()) {
            return 'none';
        }

        try {
            $core = rc_core();
            if (! is_object($core) || ! method_exists($core, 'personas')) {
                return 'none';
            }

            $resolver = $core->personas();
            if (! is_object($resolver) || ! method_exists($resolver, 'resolve')) {
                return 'none';
            }

            $persona = $resolver->resolve($user);
            return is_string($persona) && $persona !== '' ? $persona : 'none';
        } catch (Throwable) {
            return 'none';
        }
    }

    /**
     * Log through the canonical RC Core logger when available.
     *
     * @param array<string, mixed> $context Structured non-secret context.
     */
    public function info(string $message, string $action, array $context = []): void
    {
        $this->write('info', $message, $action, $context);
    }

    /**
     * @param array<string, mixed> $context Structured non-secret context.
     */
    public function warning(string $message, string $action, array $context = []): void
    {
        $this->write('warning', $message, $action, $context);
    }

    /**
     * @param array<string, mixed> $context Structured non-secret context.
     */
    private function write(string $level, string $message, string $action, array $context): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        try {
            $core = rc_core();
            if (! is_object($core) || ! method_exists($core, 'logger')) {
                return;
            }

            $logger = $core->logger();
            if (! is_object($logger) || ! method_exists($logger, $level)) {
                return;
            }

            $logger->{$level}($message, 'portal', $context, $action);
        } catch (Throwable) {
            // Bootstrap observability must never break the application runtime.
        }
    }
}
