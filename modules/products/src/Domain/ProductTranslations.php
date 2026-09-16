<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

defined('ABSPATH') || exit;

/**
 * Per-language désignation/description, common to every product family.
 *
 * Replaces Axonaut's `custom_fields` (`Désignation`, `Woo|Desc_FR`,
 * `Woo|Desc_EN`, ...), which are being phased out — this becomes the single
 * authoritative source, and doubles as the future public catalogue's
 * (Phase 4) per-language projection. `fr` is the native/required locale;
 * the others are added as they become relevant, with no schema change.
 */
final class ProductTranslations
{
    /** @return list<string> */
    public static function allowedLocales(): array
    {
        return ['fr', 'en', 'de', 'es', 'it'];
    }

    /** @param array<string,mixed> $raw */
    public static function normalize(array $raw): array
    {
        $normalized = [];
        foreach (self::allowedLocales() as $locale) {
            $entry = is_array($raw[$locale] ?? null) ? $raw[$locale] : [];
            $designation = sanitize_text_field((string) ($entry['designation'] ?? ''));
            // wp_kses_post (not sanitize_textarea_field): the description is
            // authored through a WYSIWYG editor, so its HTML must survive —
            // only markup outside the post-safe allowlist is stripped.
            $description = wp_kses_post((string) ($entry['description'] ?? ''));

            if ($designation === '' && $description === '') {
                continue;
            }

            $normalized[$locale] = ['designation' => $designation, 'description' => $description];
        }

        return $normalized;
    }
}
