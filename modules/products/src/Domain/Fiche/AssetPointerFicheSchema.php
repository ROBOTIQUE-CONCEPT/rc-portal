<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain\Fiche;

defined('ABSPATH') || exit;

/**
 * Enrichment for the `robot` and `cellule` families: a single opaque pointer.
 *
 * Per the Phase 2 design document (§0/§2.3): the Asset itself (owner, site,
 * technical referent contact, configuration, nomenclature, history, needs)
 * is owned entirely by Maintenance (Phase 5) as a durable entity that exists
 * independently of any sale. Products never duplicates or interprets that
 * data — it only carries the identifier of the Asset a commercial listing
 * represents. The value is intentionally left unvalidated against any
 * Maintenance contract: Products must not depend on a module that does not
 * exist yet, so `assetUid` simply stays empty until Maintenance ships and
 * the reconciliation happens on that side.
 */
final class AssetPointerFicheSchema implements FicheSchemaInterface
{
    public function defaults(): array
    {
        return ['assetUid' => null];
    }

    public function normalize(array $raw): array
    {
        $assetUid = trim((string) ($raw['assetUid'] ?? ''));

        return ['assetUid' => $assetUid !== '' ? sanitize_text_field($assetUid) : null];
    }
}
