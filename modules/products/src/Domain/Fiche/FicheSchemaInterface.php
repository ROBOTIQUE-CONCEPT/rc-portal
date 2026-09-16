<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain\Fiche;

defined('ABSPATH') || exit;

/**
 * Validates/sanitizes the `_rc_product_fiche` JSON payload for one product
 * family (Phase 2 design document, §2.3). Declared in PHP rather than in a
 * database schema so a family's enrichment shape can evolve without a data
 * migration.
 */
interface FicheSchemaInterface
{
    /**
     * @param array<string,mixed> $raw Untrusted input (e.g. from a submitted form).
     * @return array<string,mixed> The canonical, sanitized fiche shape for this family.
     */
    public function normalize(array $raw): array;

    /** @return array<string,mixed> Canonical empty/default shape, used when a fiche is first adopted. */
    public function defaults(): array;
}
