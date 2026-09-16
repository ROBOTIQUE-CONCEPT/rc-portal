<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

defined('ABSPATH') || exit;

/** Immutable snapshot of one `rc_product` fiche. */
final class ProductRecord
{
    /**
     * @param array<string,mixed> $fiche Family-specific enrichment (Phase 2 design document, §2.3).
     * @param array<string,mixed> $specs Common local specs (dimensions, customs) that no longer come from Axonaut's `custom_fields`.
     * @param array<string,array{designation:string,description:string}> $i18n Per-language designation/description, keyed by locale ('fr' is the native/required one).
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $uid,
        public readonly string $erpProvider,
        public readonly string $erpExternalId,
        public readonly ?string $family,
        public readonly ?string $manufacturerUid,
        public readonly string $status,
        public readonly array $fiche,
        public readonly array $specs,
        public readonly array $i18n
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClassified(): bool
    {
        return $this->family !== null && $this->family !== '';
    }

    /** The native (French) designation, when one has been entered locally. */
    public function designation(): string
    {
        return trim((string) ($this->i18n['fr']['designation'] ?? ''));
    }
}
