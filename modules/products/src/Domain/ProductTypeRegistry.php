<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

defined('ABSPATH') || exit;

/**
 * Owns the `rc_product` CPT and `rc_product_family` taxonomy registration.
 *
 * Fully headless (`show_ui` / `show_in_menu` false): the CPT is native WP
 * storage only, per the platform charter ("native WP usage preferred over
 * custom tables") — editing happens exclusively through RC Portal's own
 * `/products/...` pages, not a wp-admin editor screen. Custom flat
 * capabilities (`rc_products_read`/`rc_products_edit`) replace the default
 * WP meta-cap matrix entirely, matching the pattern already used by the
 * legacy Interventions module for its own internal-only CPT.
 */
final class ProductTypeRegistry
{
    public const POST_TYPE = 'rc_product';
    public const TAXONOMY = 'rc_product_family';
    public const META_PROJECTED = '_rc_product_family_projected';

    private const SEED_OPTION = 'rc_products_family_seed_version';

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('init', [$this, 'registerTaxonomy'], 9);
        add_action('init', [$this, 'seedFamilyTerms'], 20);
    }

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Produits RC', 'rc-portal'),
                'singular_name' => __('Produit RC', 'rc-portal'),
                'menu_name' => __('Produits RC', 'rc-portal'),
                'search_items' => __('Rechercher des produits RC', 'rc-portal'),
                'not_found' => __('Aucun produit RC trouvé.', 'rc-portal'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'rewrite' => false,
            'query_var' => false,
            // Structured business record; the native title is only used as an
            // internal admin/search label (mirrors the ERP reference/code).
            'supports' => ['title'],
            'taxonomies' => [self::TAXONOMY],
            'capability_type' => 'post',
            'map_meta_cap' => false,
            'capabilities' => [
                'edit_post' => 'rc_products_edit',
                'read_post' => 'rc_products_read',
                'delete_post' => 'rc_products_edit',
                'edit_posts' => 'rc_products_edit',
                'edit_others_posts' => 'rc_products_edit',
                'publish_posts' => 'rc_products_edit',
                'read_private_posts' => 'rc_products_read',
                'delete_posts' => 'rc_products_edit',
                'delete_private_posts' => 'rc_products_edit',
                'delete_published_posts' => 'rc_products_edit',
                'delete_others_posts' => 'rc_products_edit',
                'edit_private_posts' => 'rc_products_edit',
                'edit_published_posts' => 'rc_products_edit',
                'create_posts' => 'rc_products_edit',
            ],
            'delete_with_user' => false,
        ]);
    }

    public function registerTaxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], [
            'labels' => [
                'name' => __('Typologies produit', 'rc-portal'),
                'singular_name' => __('Typologie produit', 'rc-portal'),
            ],
            'public' => false,
            'hierarchical' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'capabilities' => [
                'manage_terms' => 'rc_products_edit',
                'edit_terms' => 'rc_products_edit',
                'delete_terms' => 'rc_products_edit',
                'assign_terms' => 'rc_products_edit',
            ],
        ]);

        register_term_meta(self::TAXONOMY, self::META_PROJECTED, [
            'type' => 'boolean',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => static fn (): bool => current_user_can('rc_products_edit'),
        ]);
    }

    /**
     * Idempotently seeds the 7 canonical family terms.
     *
     * Only creates missing terms and refreshes the `projected` meta this
     * registry owns (same "only mutate what it governs" principle as RC
     * Core's `RoleManager::apply()`) — it never deletes or renames a term,
     * and the closed list means there is no "add a term" admin screen.
     */
    public function seedFamilyTerms(): void
    {
        if (get_option(self::SEED_OPTION, '') === ProductFamilies::SEED_VERSION) {
            return;
        }

        foreach (ProductFamilies::all() as $slug => $definition) {
            $term = term_exists($slug, self::TAXONOMY);
            if (! is_array($term)) {
                $inserted = wp_insert_term($definition['label'], self::TAXONOMY, ['slug' => $slug]);
                $termId = is_array($inserted) ? (int) $inserted['term_id'] : 0;
            } else {
                $termId = (int) $term['term_id'];
            }

            if ($termId > 0) {
                update_term_meta($termId, self::META_PROJECTED, $definition['projected']);
            }
        }

        update_option(self::SEED_OPTION, ProductFamilies::SEED_VERSION, false);
    }
}
