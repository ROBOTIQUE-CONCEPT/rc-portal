<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

defined('ABSPATH') || exit;

/**
 * Result of `ProductRepository::adopt()`.
 *
 * The Axonaut `internal_id` write-back is best-effort: a failure there must
 * not roll back the local fiche RC just created, so it surfaces as a
 * non-fatal `$warning` instead of an exception.
 */
final class AdoptionResult
{
    public function __construct(
        public readonly ProductRecord $record,
        public readonly ?string $warning
    ) {
    }
}
