<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

defined('ABSPATH') || exit;

/**
 * Common local specs, no longer sourced from Axonaut's `custom_fields`:
 * dimensions (never carried by the ERP) plus code douanier/pays d'origine
 * (currently ERP-`custom_fields`-derived, but being phased out — RC Products
 * is now the authoritative source going forward).
 */
final class ProductSpecs
{
    /** @param array<string,mixed> $raw */
    public static function normalize(array $raw): array
    {
        return [
            'longueurMm' => self::dimension($raw['longueurMm'] ?? null),
            'largeurMm' => self::dimension($raw['largeurMm'] ?? null),
            'profondeurMm' => self::dimension($raw['profondeurMm'] ?? null),
            'tariffCode' => self::text($raw['tariffCode'] ?? null),
            'countryOfOrigin' => self::text($raw['countryOfOrigin'] ?? null),
        ];
    }

    private static function dimension(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = sanitize_text_field((string) $value);

        return $value !== '' ? $value : null;
    }
}
