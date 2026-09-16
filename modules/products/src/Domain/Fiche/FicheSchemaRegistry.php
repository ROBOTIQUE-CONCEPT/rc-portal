<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain\Fiche;

defined('ABSPATH') || exit;

/** Resolves the fiche schema owning a given product family. */
final class FicheSchemaRegistry
{
    /** @var array<string, FicheSchemaInterface> */
    private readonly array $schemas;

    public function __construct()
    {
        $assetPointer = new AssetPointerFicheSchema();

        $this->schemas = [
            'robot' => $assetPointer,
            'cellule' => $assetPointer,
        ];
    }

    /**
     * `null` covers both "not yet classified" (Phase 2 follow-up: family is
     * now optional at creation, see `ProductRepository::adopt()`) and the
     * families whose enrichment is still "à cadrer".
     */
    public function for(?string $family): FicheSchemaInterface
    {
        return $this->schemas[$family ?? ''] ?? new OpenFicheSchema();
    }
}
