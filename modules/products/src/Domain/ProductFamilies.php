<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

defined('ABSPATH') || exit;

/**
 * The 7 canonical product typologies (Phase 2 design document, §2.2).
 *
 * This list is an architecture decision versioned in code, not an editorial
 * taxonomy — the same principle already applied to `RolePolicy` and
 * `CapabilityRegistry` in RC Core. Bump `SEED_VERSION` whenever the list
 * below changes so `ProductTypeRegistry::seedFamilyTerms()` reconciles it.
 */
final class ProductFamilies
{
    public const SEED_VERSION = '1';

    /** @return array<string, array{label:string, projected:bool}> */
    public static function all(): array
    {
        return [
            'robot' => ['label' => __('Robot', 'rc-portal'), 'projected' => true],
            'piece' => ['label' => __('Pièce', 'rc-portal'), 'projected' => true],
            'cellule' => ['label' => __('Cellule', 'rc-portal'), 'projected' => true],
            'maintenance' => ['label' => __('Maintenance', 'rc-portal'), 'projected' => false],
            'logistique' => ['label' => __('Logistique', 'rc-portal'), 'projected' => false],
            'deplacement' => ['label' => __('Déplacement', 'rc-portal'), 'projected' => false],
            'consommable' => ['label' => __('Consommable', 'rc-portal'), 'projected' => false],
        ];
    }

    public static function isValid(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    public static function label(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return __('Non classé', 'rc-portal');
        }

        return self::all()[$slug]['label'] ?? $slug;
    }

    public static function isProjected(string $slug): bool
    {
        return (bool) (self::all()[$slug]['projected'] ?? false);
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }
}
