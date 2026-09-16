<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain\Fiche;

defined('ABSPATH') || exit;

/**
 * Fallback schema for the 4 families whose enrichment fields are
 * deliberately left "à cadrer" for a later iteration (Phase 2 design
 * document, §5): `maintenance`, `logistique`, `deplacement`, `consommable`.
 *
 * Accepts an arbitrary flat map of scalar values so the mechanism (a JSON
 * fiche validated by a per-family PHP schema) is already usable end-to-end
 * without pretending the field list is final.
 */
final class OpenFicheSchema implements FicheSchemaInterface
{
    private const MAX_FIELDS = 20;

    public function defaults(): array
    {
        return [];
    }

    public function normalize(array $raw): array
    {
        $normalized = [];
        $count = 0;

        foreach ($raw as $key => $value) {
            if ($count >= self::MAX_FIELDS) {
                break;
            }

            $key = sanitize_key((string) $key);
            if ($key === '' || ! is_scalar($value)) {
                continue;
            }

            $normalized[$key] = is_bool($value) ? $value : sanitize_text_field((string) $value);
            $count++;
        }

        return $normalized;
    }
}
