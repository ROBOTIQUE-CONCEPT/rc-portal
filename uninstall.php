<?php
/**
 * RC Portal intentionally keeps business data on uninstall.
 *
 * Data deletion must always be an explicit, separately audited operation.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
