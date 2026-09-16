<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Integration;

use RC\Portal\Modules\Products\Domain\ProductRecord;
use RC\Portal\Modules\Products\Domain\ProductRepository;
use WPRC\Core\Contracts\ERP\ProductProviderInterface;
use WPRC\Core\Contracts\Products\ProductCatalogProviderInterface;
use WPRC\Core\Data\ERP\ProductData;
use WPRC\Core\Data\Products\CatalogProductData;

defined('ABSPATH') || exit;

/**
 * Publishes `ProductCatalogProviderInterface` (RC Core) on top of RC
 * Products' own fiche storage and RC Core's ERP layer.
 *
 * This is the single point of entry every other module (Leads, Catalog,
 * Maintenance) must use to resolve an RC-enriched product — Phase 2 design
 * document, §3.
 */
final class ProductsCatalogAdapter implements ProductCatalogProviderInterface
{
    /** Pool size fetched from RC Products' own storage before ERP hydration and text filtering. */
    private const SEARCH_POOL = 500;

    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function find(string $provider, string $externalId): ?CatalogProductData
    {
        $record = $this->products->findByErpLink($provider, $externalId);
        if ($record === null) {
            return null;
        }

        $erpProduct = $this->erpProduct($record->erpExternalId);

        return $erpProduct !== null ? $this->toCatalogProductData($record, $erpProduct) : null;
    }

    public function search(string $term = '', ?string $kind = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $records = $this->products->list($kind !== '' ? $kind : null, self::SEARCH_POOL);
        if ($records === []) {
            return [];
        }

        $externalIds = array_map(static fn (ProductRecord $record): string => $record->erpExternalId, $records);
        $erpProducts = $this->erpProvider()?->findMany($externalIds) ?? [];

        $term = trim($term);
        $results = [];

        foreach ($records as $record) {
            $erpProduct = $erpProducts[$record->erpExternalId] ?? null;
            if ($erpProduct === null) {
                continue;
            }

            $catalogProduct = $this->toCatalogProductData($record, $erpProduct);
            if ($term !== '' && ! $this->matches($catalogProduct, $term)) {
                continue;
            }

            $results[] = $catalogProduct;
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function erpProduct(string $externalId): ?ProductData
    {
        return $this->erpProvider()?->find($externalId);
    }

    private function erpProvider(): ?ProductProviderInterface
    {
        if (! function_exists('rc_core')) {
            return null;
        }

        try {
            return rc_core()->erp()->get(ProductProviderInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function matches(CatalogProductData $product, string $term): bool
    {
        $haystack = mb_strtolower($product->productCode . ' ' . $product->name . ' ' . $product->designation);

        return str_contains($haystack, mb_strtolower($term));
    }

    private function toCatalogProductData(ProductRecord $record, ProductData $erpProduct): CatalogProductData
    {
        return new CatalogProductData(
            provider: $record->erpProvider,
            externalId: $record->erpExternalId,
            productCode: $erpProduct->productCode,
            name: $erpProduct->name,
            designation: $this->resolveDesignation($record, $erpProduct),
            kind: $record->family ?? '',
            manufacturerUid: $record->manufacturerUid,
            unit: $erpProduct->unit,
            price: $erpProduct->price,
            stock: $erpProduct->stock,
            disabled: $erpProduct->disabled || ! $record->isActive()
        );
    }

    /**
     * Local French désignation is now authoritative. Axonaut's own
     * `designation` (native or `custom_fields`-derived) is one of the
     * fields being phased out (Phase 2 follow-up ERP field whitelist) —
     * the only ERP fallback left, until a fiche has been filled in, is the
     * whitelisted `name`.
     */
    private function resolveDesignation(ProductRecord $record, ProductData $erpProduct): string
    {
        $local = $record->designation();

        return $local !== '' ? $local : $erpProduct->name;
    }
}
