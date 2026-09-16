<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Maintenance;

use RC\Portal\Module\EmbeddedModuleInterface;
use RC\Portal\Module\ModuleDescriptor;

/** Owns assets, maintenance contracts/plans and interventions as one bounded context. */
final class MaintenanceModule implements EmbeddedModuleInterface
{
    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor(
            id: 'maintenance',
            label: 'Maintenance',
            version: '0.1.0',
            schemaVersion: 0,
            description: 'Équipements clients, contrats de maintenance, plans préventifs et interventions techniques.',
            icon: 'M',
            order: 20,
            capabilities: ['rc_maintenance_read', 'rc_maintenance_edit']
        );
    }

    public function register(): void
    {
        // Business services will consume only RC Core contracts.
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
     * Root dashboard for `/maintenance/`. Placeholder pending the Maintenance
     * conception (Phase 5): cards for assets, mechanics, upcoming service
     * deadlines. Reuses the same "coming soon" markup the generic Portal
     * module template already renders — only the routing mechanism moves to
     * RC Core's UI Registry.
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
