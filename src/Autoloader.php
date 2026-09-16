<?php

declare(strict_types=1);

namespace RC\Portal;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        $prefix = 'RC\\Portal\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));

        if (str_starts_with($relative, 'Modules\\')) {
            $parts = explode('\\', $relative);
            $module = strtolower((string) ($parts[1] ?? ''));
            $rest = array_slice($parts, 2);
            if ($module === '' || $rest === []) {
                return;
            }

            $path = RC_PORTAL_DIR . 'modules/' . $module . '/src/' . implode('/', $rest) . '.php';
        } else {
            $path = RC_PORTAL_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        }

        if (is_file($path)) {
            require_once $path;
        }
    }
}
