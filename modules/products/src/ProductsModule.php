<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products;

use RC\Portal\Module\EmbeddedModuleInterface;
use RC\Portal\Module\ModuleDescriptor;
use RC\Portal\Modules\Products\Cli\HydrateCommand;
use RC\Portal\Modules\Products\Domain\Fiche\FicheSchemaRegistry;
use RC\Portal\Modules\Products\Domain\ProductFamilies;
use RC\Portal\Modules\Products\Domain\ProductRepository;
use RC\Portal\Modules\Products\Domain\ProductTypeRegistry;
use RC\Portal\Modules\Products\Integration\ProductsCatalogAdapter;
use RC\Portal\Modules\Products\Ui\ProductsPages;
use WPRC\Core\Contracts\Products\ProductCatalogProviderInterface;

/**
 * Owns the RC-enriched catalog of Axonaut products (Phase 2 design document).
 *
 * Scope, deliberately narrow: only products effectively available on
 * Axonaut. Mechanical models, controllers, nomenclature/BOM and
 * interventions are Maintenance's (Phase 5) — Products only ever gets
 * queried by other modules through `ProductCatalogProviderInterface`.
 *
 * Routes: `/products/` (dashboard), `/products/catalogue/[{externalId}/]`
 * (raw ERP browse + per-product fiche + reconciliation — the only sidebar
 * child entry), and one page per family slug (`/products/{family}/[{uid}/]`)
 * so a reconciled, classified fiche lives at its own canonical URL. Family
 * pages are deliberately kept out of the sidebar: they are reached from the
 * dashboard cards and from the catalogue once a product is reconciled.
 */
final class ProductsModule implements EmbeddedModuleInterface
{
    private ?ProductRepository $repository = null;
    private ?ProductsPages $pages = null;

    public function descriptor(): ModuleDescriptor
    {
        return new ModuleDescriptor(
            id: 'products',
            label: 'Produits',
            version: '0.3.0',
            schemaVersion: 1,
            description: 'Produits Axonaut adoptés, enrichis localement par typologie et publiés vers le catalogue public.',
            icon: 'P',
            order: 30,
            capabilities: ['rc_products_read', 'rc_products_edit']
        );
    }

    public function register(): void
    {
        $descriptor = $this->descriptor();

        rc_register_capabilities($descriptor->id, $descriptor->capabilities);

        (new ProductTypeRegistry())->register();

        $pages = $this->pages();

        rc_register_ui_page(
            module: $descriptor->id,
            surface: 'internal',
            path: '',
            label: $descriptor->label,
            capability: 'rc_products_read',
            renderer: [$pages, 'renderDashboard']
        );

        rc_register_ui_page(
            module: $descriptor->id,
            surface: 'internal',
            path: 'catalogue',
            label: 'Catalogues',
            capability: 'rc_products_read',
            renderer: [$pages, 'renderCatalogue'],
            parentPath: ''
        );

        foreach (ProductFamilies::all() as $slug => $definition) {
            rc_register_ui_page(
                module: $descriptor->id,
                surface: 'internal',
                path: $slug,
                label: $definition['label'],
                capability: 'rc_products_read',
                renderer: [$pages, 'renderFamily'],
                navigation: false
            );
        }

        $this->registerCliCommand();
    }

    public function boot(): void
    {
        if (! function_exists('rc_core')) {
            return;
        }

        // Direct ServiceRegistry publication from the module's own boot,
        // mirroring rc-catalog's `ProductContextProviderInterface` binding
        // (Core has already fully booted by the time Portal reaches
        // `plugins_loaded` priority 20 — see the Phase 2 design document, §3).
        rc_core()->services()->instance(
            ProductCatalogProviderInterface::class,
            new ProductsCatalogAdapter($this->repository()),
            true
        );
    }

    private function registerCliCommand(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_command('rc products hydrate', new HydrateCommand($this->repository()));
    }

    private function repository(): ProductRepository
    {
        return $this->repository ??= new ProductRepository(new FicheSchemaRegistry());
    }

    private function pages(): ProductsPages
    {
        return $this->pages ??= new ProductsPages($this->repository());
    }
}
