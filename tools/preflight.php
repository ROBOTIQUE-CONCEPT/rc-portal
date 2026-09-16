<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $phpFiles[] = $path;
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $errors[] = 'PHP lint failed: ' . $path . ' :: ' . implode(' ', $output);
    }
}

$checks = [
    'switch_to_blog(' => 'Multisite cross-site dependency',
    '$wpdb->base_prefix' => 'Network-global database dependency',
    'wp_remote_get(' => 'Direct HTTP call; use RC Core ERP/HTTP contracts',
    'wp_remote_post(' => 'Direct HTTP call; use RC Core ERP/HTTP contracts',
    'wp_upload_dir(' => 'Business files must not use the WordPress public uploads directory',
];

$crossModulePattern = '/use\s+RC\\\\Portal\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/';

foreach ($phpFiles as $path) {
    if (realpath($path) === realpath(__FILE__)) {
        continue;
    }

    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $source = (string) file_get_contents($path);

    foreach ($checks as $needle => $label) {
        if (str_contains($source, $needle)) {
            $errors[] = $label . ': ' . $relative;
        }
    }

    if (preg_match('#^modules/([^/]+)/#', str_replace('\\', '/', $relative), $ownerMatch)) {
        $owner = strtolower($ownerMatch[1]);
        if (preg_match_all($crossModulePattern, $source, $matches)) {
            foreach ($matches[1] as $imported) {
                if (strtolower($imported) !== $owner) {
                    $errors[] = sprintf('Cross-module import in %s: %s', $relative, $imported);
                }
            }
        }
    }
}

foreach (['assets', 'templates', 'src/Presentation'] as $forbiddenDirectory) {
    if (is_dir($root . '/' . $forbiddenDirectory)) {
        $errors[] = 'Frontend presentation directory must live in the Portal theme: ' . $forbiddenDirectory;
    }
}

echo "RC Portal " . (defined('RC_PORTAL_VERSION') ? RC_PORTAL_VERSION : 'source') . " — Preflight\n";
echo "===================================\n";
echo 'PHP lint: ' . ($errors === [] ? 'GREEN' : 'CHECK') . ' (' . count($phpFiles) . " files)\n";
echo "Frontend presentation bundled: 0 expected\n";
echo "Direct ERP HTTP: 0 expected\n";
echo "switch_to_blog(): 0 expected\n";
echo '$wpdb->base_prefix: 0 expected' . "\n";
echo "Cross-module imports: 0 expected\n";
echo "Webroot business storage calls: 0 expected\n\n";

if ($errors !== []) {
    foreach ($errors as $error) {
        echo '[FAIL] ' . $error . "\n";
    }
    echo "\nRESULT: RED\n";
    exit(1);
}

echo "RESULT: GREEN\n";
