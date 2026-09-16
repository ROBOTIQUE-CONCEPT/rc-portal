<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Domain;

use RC\Portal\Modules\Products\Domain\Fiche\FicheSchemaRegistry;
use RuntimeException;
use WP_Query;
use WPRC\Core\Contracts\ERP\ProductProviderInterface;

defined('ABSPATH') || exit;

/**
 * Owns the `rc_product` CPT/postmeta/taxonomy storage.
 *
 * No other module may query these posts directly (Phase 2 design document,
 * §3): everything crosses module boundaries through
 * `ProductCatalogProviderInterface`, published by `ProductsCatalogAdapter`.
 */
final class ProductRepository
{
    private const META_UID = '_rc_product_uid';
    private const META_ERP_PROVIDER = '_rc_product_erp_provider';
    private const META_ERP_EXTERNAL_ID = '_rc_product_erp_external_id';
    private const META_MANUFACTURER_UID = '_rc_product_manufacturer_uid';
    private const META_STATUS = '_rc_product_status';
    private const META_FICHE = '_rc_product_fiche';
    private const META_SPECS = '_rc_product_specs';
    private const META_I18N = '_rc_product_i18n';

    public function __construct(private readonly FicheSchemaRegistry $schemas)
    {
    }

    public function find(int $postId): ?ProductRecord
    {
        $post = get_post($postId);
        if (! $post || $post->post_type !== ProductTypeRegistry::POST_TYPE) {
            return null;
        }

        $terms = wp_get_object_terms($postId, ProductTypeRegistry::TAXONOMY, ['fields' => 'slugs']);
        $family = is_array($terms) && $terms !== [] ? (string) $terms[0] : null;

        $fiche = json_decode((string) get_post_meta($postId, self::META_FICHE, true), true);
        $specs = json_decode((string) get_post_meta($postId, self::META_SPECS, true), true);
        $i18n = json_decode((string) get_post_meta($postId, self::META_I18N, true), true);
        $manufacturerUid = trim((string) get_post_meta($postId, self::META_MANUFACTURER_UID, true));
        $status = trim((string) get_post_meta($postId, self::META_STATUS, true));

        return new ProductRecord(
            postId: $postId,
            uid: (string) get_post_meta($postId, self::META_UID, true),
            erpProvider: (string) get_post_meta($postId, self::META_ERP_PROVIDER, true),
            erpExternalId: (string) get_post_meta($postId, self::META_ERP_EXTERNAL_ID, true),
            family: $family,
            manufacturerUid: $manufacturerUid !== '' ? $manufacturerUid : null,
            status: $status !== '' ? $status : 'active',
            fiche: is_array($fiche) ? $fiche : [],
            specs: is_array($specs) ? $specs : [],
            i18n: is_array($i18n) ? $i18n : []
        );
    }

    public function findByUid(string $uid): ?ProductRecord
    {
        $uid = trim($uid);
        if ($uid === '') {
            return null;
        }

        $ids = (new WP_Query([
            'post_type' => ProductTypeRegistry::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => self::META_UID,
            'meta_value' => $uid,
        ]))->posts;

        return $ids !== [] ? $this->find((int) $ids[0]) : null;
    }

    public function findByErpLink(string $provider, string $externalId): ?ProductRecord
    {
        $provider = sanitize_key($provider);
        $externalId = trim($externalId);
        if ($provider === '' || $externalId === '') {
            return null;
        }

        $ids = (new WP_Query([
            'post_type' => ProductTypeRegistry::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                ['key' => self::META_ERP_PROVIDER, 'value' => $provider],
                ['key' => self::META_ERP_EXTERNAL_ID, 'value' => $externalId],
            ],
        ]))->posts;

        return $ids !== [] ? $this->find((int) $ids[0]) : null;
    }

    /**
     * @param string|null $family Pass null for "any family, including unclassified".
     * @return list<ProductRecord>
     */
    public function list(?string $family = null, int $limit = 50, int $offset = 0): array
    {
        $args = [
            'post_type' => ProductTypeRegistry::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => max(1, min(500, $limit)),
            'offset' => max(0, $offset),
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if ($family !== null && $family !== '') {
            $args['tax_query'] = [[
                'taxonomy' => ProductTypeRegistry::TAXONOMY,
                'field' => 'slug',
                'terms' => [$family],
            ]];
        }

        $records = [];
        foreach ((new WP_Query($args))->posts as $postId) {
            $record = $this->find((int) $postId);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /** @return array<string,int> */
    public function countsByFamily(): array
    {
        $counts = [];
        foreach (ProductFamilies::slugs() as $slug) {
            $query = new WP_Query([
                'post_type' => ProductTypeRegistry::POST_TYPE,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'tax_query' => [[
                    'taxonomy' => ProductTypeRegistry::TAXONOMY,
                    'field' => 'slug',
                    'terms' => [$slug],
                ]],
            ]);
            $counts[$slug] = (int) $query->found_posts;
        }

        return $counts;
    }

    public function countReconciled(): int
    {
        $query = new WP_Query([
            'post_type' => ProductTypeRegistry::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        return (int) $query->found_posts;
    }

    /**
     * Reconciles an Axonaut product into a new RC fiche ("le CPT porteur").
     *
     * Deliberate, not automatic (Phase 2 design document, §2.1): a fiche is
     * only created when an operator (or the CLI hydration script) links a
     * real Axonaut product. `$family` is optional — a fiche can be created
     * unclassified and enriched/classified later from its own page; this is
     * what the CLI hydration script relies on to bulk-create carrier posts.
     * One Axonaut product may be reconciled at most once.
     */
    public function adopt(string $provider, string $externalId, ?string $family, ?string $manufacturerUid, string $title): AdoptionResult
    {
        $provider = sanitize_key($provider);
        $externalId = trim($externalId);
        $family = $family !== null ? sanitize_key($family) : null;

        if ($provider === '' || $externalId === '') {
            throw new RuntimeException(__('Le produit Axonaut à réconcilier est invalide.', 'rc-portal'));
        }

        if ($family !== null && $family !== '' && ! ProductFamilies::isValid($family)) {
            throw new RuntimeException(__('Typologie de produit invalide.', 'rc-portal'));
        }

        if ($this->findByErpLink($provider, $externalId) !== null) {
            throw new RuntimeException(__('Ce produit Axonaut est déjà rattaché à une fiche RC.', 'rc-portal'));
        }

        $title = sanitize_text_field($title);
        $postId = wp_insert_post([
            'post_type' => ProductTypeRegistry::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title !== '' ? $title : $externalId,
        ], true);

        if (is_wp_error($postId) || ! $postId) {
            throw new RuntimeException(__('Impossible de créer la fiche produit.', 'rc-portal'));
        }

        $postId = (int) $postId;
        $uid = 'PRD-' . rc_core()->uid()->generateUnique(
            fn (string $candidate): bool => $this->findByUid('PRD-' . $candidate) instanceof ProductRecord
        );

        update_post_meta($postId, self::META_UID, $uid);
        update_post_meta($postId, self::META_ERP_PROVIDER, $provider);
        update_post_meta($postId, self::META_ERP_EXTERNAL_ID, $externalId);
        update_post_meta($postId, self::META_MANUFACTURER_UID, (string) $manufacturerUid);
        update_post_meta($postId, self::META_STATUS, 'active');
        update_post_meta($postId, self::META_FICHE, (string) wp_json_encode($this->schemas->for($family)->defaults()));
        update_post_meta($postId, self::META_SPECS, (string) wp_json_encode(ProductSpecs::normalize([])));
        update_post_meta($postId, self::META_I18N, (string) wp_json_encode([]));

        if ($family !== null && $family !== '') {
            wp_set_object_terms($postId, [$family], ProductTypeRegistry::TAXONOMY, false);
        }

        $warning = $this->writeBackInternalId($externalId, $postId);

        $record = $this->find($postId);
        if ($record === null) {
            throw new RuntimeException(__('La fiche produit vient d’être créée mais ne peut pas être relue.', 'rc-portal'));
        }

        return new AdoptionResult($record, $warning);
    }

    public function setFamily(int $postId, ?string $family): void
    {
        $family = $family !== null ? sanitize_key($family) : null;
        if ($family !== null && $family !== '' && ! ProductFamilies::isValid($family)) {
            throw new RuntimeException(__('Typologie de produit invalide.', 'rc-portal'));
        }

        if ($family === null || $family === '') {
            wp_set_object_terms($postId, [], ProductTypeRegistry::TAXONOMY, false);
        } else {
            wp_set_object_terms($postId, [$family], ProductTypeRegistry::TAXONOMY, false);
        }

        // Re-normalize the existing fiche through the newly assigned family's
        // schema so a leftover shape from a previous family cannot linger
        // (each schema's normalize() only keeps the keys it recognizes).
        $record = $this->find($postId);
        if ($record !== null) {
            update_post_meta($postId, self::META_FICHE, (string) wp_json_encode($this->schemas->for($family)->normalize($record->fiche)));
        }
    }

    public function saveCommon(int $postId, ?string $manufacturerUid, string $status): void
    {
        $status = in_array($status, ['active', 'archived'], true) ? $status : 'active';
        update_post_meta($postId, self::META_MANUFACTURER_UID, (string) $manufacturerUid);
        update_post_meta($postId, self::META_STATUS, $status);
    }

    /** @param array<string,mixed> $raw */
    public function saveFiche(int $postId, array $raw): void
    {
        $record = $this->find($postId);
        if ($record === null) {
            throw new RuntimeException(__('Fiche produit introuvable.', 'rc-portal'));
        }

        $normalized = $this->schemas->for($record->family)->normalize($raw);
        update_post_meta($postId, self::META_FICHE, (string) wp_json_encode($normalized));
    }

    /** @param array<string,mixed> $raw */
    public function saveSpecs(int $postId, array $raw): void
    {
        update_post_meta($postId, self::META_SPECS, (string) wp_json_encode(ProductSpecs::normalize($raw)));
    }

    /** @param array<string,mixed> $raw */
    public function saveI18n(int $postId, array $raw): void
    {
        update_post_meta($postId, self::META_I18N, (string) wp_json_encode(ProductTranslations::normalize($raw)));
    }

    /**
     * Best-effort Axonaut `internal_id` write-back. Never throws: a failed
     * write-back must not prevent the local fiche from being created
     * (Phase 2 follow-up design note) — it comes back as a warning string.
     */
    private function writeBackInternalId(string $externalId, int $postId): ?string
    {
        if (! function_exists('rc_core')) {
            return __('Impossible d’écrire l’identifiant RC sur Axonaut (Core indisponible).', 'rc-portal');
        }

        try {
            $ok = rc_core()->erp()->get(ProductProviderInterface::class)->updateInternalId($externalId, (string) $postId);
        } catch (\Throwable $exception) {
            $ok = false;
        }

        return $ok ? null : __('La fiche RC a été créée, mais l’écriture de l’identifiant interne sur Axonaut a échoué — à réessayer plus tard.', 'rc-portal');
    }
}
