<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Inventory;

use RC\Portal\Module\EmbeddedModuleInterface;
use RC\Portal\Module\ModuleDescriptor;

/** Owns RC serialized stock rules that are not delegated to the ERP. */
final class InventoryModule implements EmbeddedModuleInterface
{
    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor(
            id: 'inventory',
            label: 'Inventaire',
            version: '0.1.0',
            schemaVersion: 0,
            description: 'Stock propre, consignation client, sérialisation et historique des mouvements spécifiques RC.',
            icon: 'I',
            order: 40,
            capabilities: ['rc_inventory_read', 'rc_inventory_edit']
        );
    }

    public function register(): void
    {
        $descriptor = $this->descriptor();

        rc_register_capabilities($descriptor->id, $descriptor->capabilities);

        rc_register_ui_page(
            module: $descriptor->id,
            surface: 'internal',
            path: '',
            label: $descriptor->label,
            capability: $descriptor->capabilities[0] ?? $descriptor->requiredCapability,
            renderer: [$this, 'renderDashboard']
        );
    }

    public function boot(): void
    {
    }

    /**
     * Root dashboard for `/inventory/`. Reuses the same "coming soon" markup
     * the generic Portal module template already renders — only the routing
     * mechanism moves to RC Core's UI Registry.
     */
    public function renderDashboard(object $context): string
    {
        $descriptor = $this->descriptor();
        ob_start();
        ?>
        <div class="rc-empty">
            <span class="rc-empty__icon" aria-hidden="true"><?php echo esc_html($descriptor->icon); ?></span>
            <strong><?php echo esc_html($descriptor->label); ?></strong>
            <p><?php echo esc_html($descriptor->description); ?></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
