<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools;

use RC\Portal\Module\EmbeddedModuleInterface;
use RC\Portal\Module\ModuleDescriptor;
use RC\Portal\Modules\Tools\Ui\ToolsPages;

/**
 * "Outils internes" — a loose, growing collection of standalone technical
 * tools with no relationship to the ERP or any other module's data. Each
 * tool lives in its own namespace under `src/` (e.g. `KukaArchive/`) and is
 * wired into this module's page list independently; there is deliberately
 * no shared domain model between tools, only the module shell (navigation,
 * capability, page registration) that hosts them.
 *
 * First tool: a KUKA controller archive (.zip) analyzer for field
 * diagnostics. Uploaded archives are parsed entirely in memory for the
 * duration of the request and never written to disk or stored anywhere —
 * see KukaArchive\KukaArchiveAnalyzer and Ui\ToolsPages::renderKukaArchive().
 */
final class ToolsModule implements EmbeddedModuleInterface
{
    private ?ToolsPages $pages = null;

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor(
            id: 'tools',
            label: 'Outils internes',
            version: '0.1.0',
            schemaVersion: 1,
            description: 'Collection d\'outils techniques internes (diagnostics, utilitaires) sans lien avec l\'ERP ou les données métier.',
            icon: 'O',
            order: 90,
            capabilities: ['rc_tools_use']
        );
    }

    public function register(): void
    {
        $descriptor = $this->descriptor();

        rc_register_capabilities($descriptor->id, $descriptor->capabilities);

        $pages = $this->pages();

        rc_register_ui_page(
            module: $descriptor->id,
            surface: 'internal',
            path: '',
            label: $descriptor->label,
            capability: 'rc_tools_use',
            renderer: [$pages, 'renderDashboard']
        );

        rc_register_ui_page(
            module: $descriptor->id,
            surface: 'internal',
            path: 'kuka-archive',
            label: 'Analyseur d\'archive KUKA',
            capability: 'rc_tools_use',
            renderer: [$pages, 'renderKukaArchive'],
            parentPath: ''
        );
    }

    public function boot(): void
    {
        // No cross-module contracts to publish: this module is intentionally
        // self-contained (see class docblock).
    }

    private function pages(): ToolsPages
    {
        return $this->pages ??= new ToolsPages();
    }
}
