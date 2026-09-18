<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Ui;

use RC\Portal\Modules\Products\Domain\ProductFamilies;
use RC\Portal\Modules\Products\Domain\ProductRecord;
use RC\Portal\Modules\Products\Domain\ProductRepository;
use RC\Portal\Modules\Products\Domain\ProductTranslations;
use WPRC\Core\Contracts\ERP\ProductProviderInterface;
use WPRC\Core\Data\ERP\ProductData;

defined('ABSPATH') || exit;

/**
 * Renders the `products` module's RC Portal pages (`UiRegistry`).
 *
 * Flow (Phase 2 design document follow-up, feedback round 2): browse the raw
 * Axonaut catalog (`/products/catalogue/`), open a dedicated fiche per ERP
 * product (`/products/catalogue/{externalId}/`), and — at "réconciliation" —
 * create the local `rc_product` CPT and enrich it inline, right there. Once
 * a typology is chosen the fiche graduates to its canonical, per-family URL
 * (`/products/{family}/{uid}/`), which is where every subsequent edit and
 * every other module's link points.
 */
final class ProductsPages
{
    /**
     * The only Axonaut product fields still read (Phase 2 follow-up): every
     * other native field, and all of `custom_fields`, is being phased out —
     * the local fiche (specs/i18n) is now the authoritative source.
     *
     * @var array<string,string>
     */
    /**
     * Three fixed columns for `renderErpCard`'s 3-column layout (stacked on
     * mobile — see `.rc-erp-columns` in portal.css); `description` is
     * deliberately absent from all three — it's raw HTML from Axonaut and
     * gets its own full-width row below, rendered through wp_kses() rather
     * than escaped as text.
     */
    private const ERP_FIELD_GROUPS = [
        'Identité' => [
            'nativeType' => 'Type',
            'category' => 'Catégorie',
            'supplierReference' => 'Code produit fournisseur',
            'internalId' => 'Identifiant RC',
        ],
        'Tarification' => [
            'price' => 'Prix HT',
            'priceWithTax' => 'Prix TTC',
            'taxRate' => 'Taux de TVA (%)',
        ],
        'Stock' => [
            'stock' => 'Stock',
            'weightedAverageCost' => 'Coût moyen',
            'jobCosting' => 'Coût de revient',
        ],
    ];

    /**
     * Axonaut's product description is raw HTML built by its own rich-text
     * editor — typically `<div style="...">` / `<span id="meta[...]"
     * style="...">` wrappers around plain lists and paragraphs. wp_kses_post's
     * default allowlist strips `style` and `id`, which is what turned this
     * into visibly-escaped tag soup before; this allowlist keeps `style`
     * (WordPress runs its value through safecss_filter_attr() regardless)
     * and `id` so Axonaut's own formatting survives, while still refusing
     * scripts, forms, iframes and event handlers.
     */
    private const ERP_DESCRIPTION_ALLOWED_HTML = [
        'div' => ['id' => true, 'class' => true, 'style' => true],
        'span' => ['id' => true, 'class' => true, 'style' => true],
        'p' => ['id' => true, 'class' => true, 'style' => true],
        'br' => [],
        'hr' => [],
        'strong' => ['style' => true],
        'b' => ['style' => true],
        'em' => ['style' => true],
        'i' => ['style' => true],
        'u' => ['style' => true],
        'ul' => ['id' => true, 'class' => true, 'style' => true],
        'ol' => ['id' => true, 'class' => true, 'style' => true],
        'li' => ['id' => true, 'class' => true, 'style' => true],
        'a' => ['href' => true, 'target' => true, 'rel' => true, 'style' => true],
        'table' => ['class' => true, 'style' => true],
        'thead' => [],
        'tbody' => [],
        'tr' => ['style' => true],
        'td' => ['style' => true, 'colspan' => true, 'rowspan' => true],
        'th' => ['style' => true, 'colspan' => true, 'rowspan' => true],
        'h1' => ['style' => true],
        'h2' => ['style' => true],
        'h3' => ['style' => true],
        'h4' => ['style' => true],
    ];

    public function __construct(private readonly ProductRepository $repository)
    {
    }

    // -- Dashboard -----------------------------------------------------

    public function renderDashboard(object $context): string
    {
        $filters = [
            'q' => isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '',
            'family' => isset($_GET['family']) ? sanitize_key((string) $_GET['family']) : '',
            'status' => isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '',
            'orderby' => isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'title',
            'order' => isset($_GET['order']) && strtolower((string) $_GET['order']) === 'desc' ? 'desc' : 'asc',
        ];
        $perPage = 20;
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        $result = $this->repository->search($filters, $perPage, $page);
        $records = $result['records'];
        $total = $result['total'];
        $totalPages = (int) max(1, ceil($total / $perPage));

        $provider = function_exists('rc_core') ? rc_core()->erp()->activeSource() : 'axonaut';
        $erpProducts = [];
        if ($records !== []) {
            $externalIds = array_map(static fn (ProductRecord $record): string => $record->erpExternalId, $records);
            try {
                $erpProducts = $this->erpProvider()->findMany($externalIds);
            } catch (\Throwable $exception) {
                $erpProducts = [];
            }
        }

        $columns = [
            'uid' => __('Référence RC', 'rc-portal'),
            'title' => __('Désignation', 'rc-portal'),
            'status' => __('Statut', 'rc-portal'),
            'date' => __('Créé le', 'rc-portal'),
        ];

        ob_start();
        ?>
        <div class="rc-products-dashboard">
            <div class="rc-dashboard-actions">
                <a class="rc-button" href="<?php echo esc_url(home_url('/products/catalogue/')); ?>">
                    <?php esc_html_e('Ouvrir le catalogue Axonaut', 'rc-portal'); ?>
                </a>
            </div>

            <form method="get" class="rc-toolbar">
                <label>
                    <?php esc_html_e('Rechercher (référence, désignation)', 'rc-portal'); ?>
                    <input type="search" name="q" value="<?php echo esc_attr($filters['q']); ?>">
                </label>
                <label>
                    <?php esc_html_e('Typologie', 'rc-portal'); ?>
                    <select name="family">
                        <option value=""><?php esc_html_e('Toutes', 'rc-portal'); ?></option>
                        <?php foreach (ProductFamilies::all() as $slug => $definition) : ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['family'], $slug); ?>>
                                <?php echo esc_html($definition['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php esc_html_e('Statut', 'rc-portal'); ?>
                    <select name="status">
                        <option value=""><?php esc_html_e('Tous', 'rc-portal'); ?></option>
                        <option value="active" <?php selected($filters['status'], 'active'); ?>><?php esc_html_e('Actif', 'rc-portal'); ?></option>
                        <option value="archived" <?php selected($filters['status'], 'archived'); ?>><?php esc_html_e('Archivé', 'rc-portal'); ?></option>
                    </select>
                </label>
                <input type="hidden" name="orderby" value="<?php echo esc_attr($filters['orderby']); ?>">
                <input type="hidden" name="order" value="<?php echo esc_attr($filters['order']); ?>">
                <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Filtrer', 'rc-portal'); ?></button>
            </form>

            <?php if ($records === []) : ?>
                <div class="rc-empty">
                    <strong><?php esc_html_e('Aucun produit ne correspond à ces critères.', 'rc-portal'); ?></strong>
                </div>
            <?php else : ?>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $key => $label) : ?>
                                    <th><?php echo $this->sortLink($key, $label, $filters); ?></th>
                                <?php endforeach; ?>
                                <th><?php esc_html_e('Typologie', 'rc-portal'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record) : ?>
                                <?php $erpProduct = $erpProducts[$record->erpExternalId] ?? null; ?>
                                <tr>
                                    <td><?php echo esc_html($record->uid); ?></td>
                                    <td>
                                        <?php
                                        $designation = $record->designation();
                                        if ($designation === '' && $erpProduct !== null) {
                                            $designation = $erpProduct->name;
                                        }
                                        echo esc_html($designation);
                                        ?>
                                    </td>
                                    <td>
                                        <span class="rc-badge <?php echo $record->isActive() ? 'rc-badge--success' : 'rc-badge--muted'; ?>">
                                            <?php echo esc_html($record->isActive() ? __('Actif', 'rc-portal') : __('Archivé', 'rc-portal')); ?>
                                        </span>
                                    </td>
                                    <td></td>
                                    <td>
                                        <?php if ($record->isClassified()) : ?>
                                            <span class="rc-badge rc-badge--success"><?php echo esc_html(ProductFamilies::label($record->family)); ?></span>
                                        <?php else : ?>
                                            <span class="rc-badge rc-badge--accent"><?php esc_html_e('À classifier', 'rc-portal'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record->isClassified()) : ?>
                                            <a href="<?php echo esc_url(home_url('/products/' . $record->family . '/' . rawurlencode($record->uid) . '/')); ?>">
                                                <?php esc_html_e('Ouvrir', 'rc-portal'); ?>
                                            </a>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url(home_url('/products/catalogue/' . rawurlencode($record->erpExternalId) . '/')); ?>">
                                                <?php esc_html_e('Classifier', 'rc-portal'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php echo $this->paginationLinks($page, $totalPages, $filters); ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string,string> $filters */
    private function sortLink(string $column, string $label, array $filters): string
    {
        $isActive = $filters['orderby'] === $column;
        $nextOrder = $isActive && $filters['order'] === 'asc' ? 'desc' : 'asc';
        $url = $this->dashboardUrl(array_merge($filters, ['orderby' => $column, 'order' => $nextOrder, 'paged' => 1]));
        $arrow = $isActive ? ($filters['order'] === 'asc' ? ' ↑' : ' ↓') : '';

        return '<a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a>';
    }

    /** @param array<string,string|int> $filters */
    private function paginationLinks(int $page, int $totalPages, array $filters): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        ob_start();
        ?>
        <nav class="rc-pagination" aria-label="<?php esc_attr_e('Pagination', 'rc-portal'); ?>">
            <?php if ($page > 1) : ?>
                <a class="rc-button" href="<?php echo esc_url($this->dashboardUrl(array_merge($filters, ['paged' => $page - 1]))); ?>">&larr; <?php esc_html_e('Précédent', 'rc-portal'); ?></a>
            <?php endif; ?>
            <span class="rc-pagination__status">
                <?php echo esc_html(sprintf(
                    /* translators: 1: current page, 2: total pages */
                    __('Page %1$d sur %2$d', 'rc-portal'),
                    $page,
                    $totalPages
                )); ?>
            </span>
            <?php if ($page < $totalPages) : ?>
                <a class="rc-button" href="<?php echo esc_url($this->dashboardUrl(array_merge($filters, ['paged' => $page + 1]))); ?>"><?php esc_html_e('Suivant', 'rc-portal'); ?> &rarr;</a>
            <?php endif; ?>
        </nav>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string,string|int> $overrides */
    private function dashboardUrl(array $overrides): string
    {
        return add_query_arg($overrides, home_url('/products/'));
    }

    // -- Catalogue (raw ERP browse + reconciliation) --------------------

    public function renderCatalogue(object $context): string
    {
        $externalId = trim((string) ($context->remainder ?? ''), '/');

        return $externalId !== '' ? $this->renderCatalogueFiche($externalId) : $this->renderCatalogueList();
    }

    private function renderCatalogueList(): string
    {
        $term = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $provider = function_exists('rc_core') ? rc_core()->erp()->activeSource() : 'axonaut';
        $products = [];
        $erpError = null;

        try {
            $products = $term !== ''
                ? $this->erpProvider()->search($term, 100, true)
                : $this->erpProvider()->all(true, 300);
        } catch (\Throwable $exception) {
            $erpError = __('Connexion au catalogue Axonaut indisponible pour le moment.', 'rc-portal');
        }

        ob_start();
        ?>
        <div class="rc-products-catalogue">
            <header class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Axonaut', 'rc-portal'); ?></span>
                    <h1><?php esc_html_e('Catalogue', 'rc-portal'); ?></h1>
                </div>
            </header>

            <?php if ($erpError !== null) : ?>
                <div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($erpError); ?></div>
            <?php endif; ?>

            <form method="get" class="rc-toolbar">
                <label>
                    <?php esc_html_e('Rechercher (code, nom)', 'rc-portal'); ?>
                    <input type="search" name="q" value="<?php echo esc_attr($term); ?>">
                </label>
                <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Rechercher', 'rc-portal'); ?></button>
            </form>

            <?php if ($products === [] && $erpError === null) : ?>
                <div class="rc-empty">
                    <strong><?php esc_html_e('Aucun produit Axonaut trouvé.', 'rc-portal'); ?></strong>
                </div>
            <?php else : ?>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Code', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Nom', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Catégorie', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Statut RC', 'rc-portal'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product) : /** @var ProductData $product */ ?>
                                <?php $record = $this->repository->findByErpLink($provider, $product->externalId); ?>
                                <tr>
                                    <td><?php echo esc_html($product->productCode); ?></td>
                                    <td><?php echo esc_html($product->name); ?></td>
                                    <td><?php echo esc_html($product->category); ?></td>
                                    <td>
                                        <?php if ($record === null) : ?>
                                            <span class="rc-badge"><?php esc_html_e('Non réconcilié', 'rc-portal'); ?></span>
                                        <?php elseif ($record->isClassified()) : ?>
                                            <span class="rc-badge rc-badge--success"><?php echo esc_html(ProductFamilies::label($record->family)); ?></span>
                                        <?php else : ?>
                                            <span class="rc-badge rc-badge--accent"><?php esc_html_e('À classifier', 'rc-portal'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(home_url('/products/catalogue/' . rawurlencode($product->externalId) . '/')); ?>">
                                            <?php esc_html_e('Ouvrir', 'rc-portal'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function renderCatalogueFiche(string $externalId): string
    {
        $provider = function_exists('rc_core') ? rc_core()->erp()->activeSource() : 'axonaut';
        $erpProduct = null;
        $erpError = null;

        try {
            $erpProduct = $this->erpProvider()->find($externalId);
        } catch (\Throwable $exception) {
            $erpError = __('Connexion au catalogue Axonaut indisponible pour le moment.', 'rc-portal');
        }

        if ($erpProduct === null && $erpError === null) {
            return $this->notFound(__('Produit Axonaut introuvable.', 'rc-portal'));
        }

        $record = $this->repository->findByErpLink($provider, $externalId);
        if ($record !== null && $record->isClassified()) {
            wp_safe_redirect(home_url('/products/' . $record->family . '/' . rawurlencode($record->uid) . '/'));
            exit;
        }

        $canEdit = current_user_can('rc_products_edit');
        $error = null;
        $warning = null;
        $saved = false;

        if ($canEdit && $record === null && $erpProduct !== null && $this->isPost('reconcile')) {
            if (! wp_verify_nonce($this->postValue('rc_products_nonce'), 'rc_products_reconcile_' . $externalId)) {
                $error = __('La demande a expiré, veuillez réessayer.', 'rc-portal');
            } else {
                try {
                    $family = sanitize_key($this->postValue('family'));
                    $manufacturerUid = $this->postValue('manufacturer_uid');
                    $title = $erpProduct->productCode !== '' ? $erpProduct->productCode : $erpProduct->name;

                    $result = $this->repository->adopt(
                        $provider,
                        $externalId,
                        $family !== '' ? $family : null,
                        $manufacturerUid !== '' ? $manufacturerUid : null,
                        $title
                    );

                    if ($result->record->isClassified()) {
                        wp_safe_redirect(home_url('/products/' . $result->record->family . '/' . rawurlencode($result->record->uid) . '/'));
                        exit;
                    }

                    $record = $result->record;
                    $warning = $result->warning;
                    $saved = true;
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        } elseif ($canEdit && $record !== null && $this->isPost('enrich')) {
            if (! wp_verify_nonce($this->postValue('rc_products_nonce'), 'rc_products_enrich_' . $record->uid)) {
                $error = __('La demande a expiré, veuillez réessayer.', 'rc-portal');
            } else {
                try {
                    $family = sanitize_key($this->postValue('family'));
                    $manufacturerUid = $this->postValue('manufacturer_uid');
                    // Status is not a local editorial field: derived from
                    // Axonaut's own `disabled` flag — see renderFamilyFiche().
                    $status = ($erpProduct !== null && $erpProduct->disabled) ? 'archived' : 'active';

                    $this->repository->saveCommon($record->postId, $manufacturerUid !== '' ? $manufacturerUid : null, $status);
                    $this->repository->saveI18n($record->postId, $this->i18nInputFromRequest());
                    $this->repository->saveSpecs($record->postId, $this->specsInputFromRequest());

                    if ($family !== '' && ProductFamilies::isValid($family)) {
                        $this->repository->setFamily($record->postId, $family);
                        wp_safe_redirect(home_url('/products/' . $family . '/' . rawurlencode($record->uid) . '/'));
                        exit;
                    }

                    $record = $this->repository->find($record->postId) ?? $record;
                    $saved = true;
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        $manufacturers = function_exists('rc_core') ? rc_core()->manufacturers()->all() : [];

        ob_start();
        ?>
        <div class="rc-products-fiche">
            <header class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php echo esc_html(($erpProduct !== null && $erpProduct->productCode !== '') ? $erpProduct->productCode : ('Axonaut #' . $externalId)); ?></span>
                    <h1><?php echo esc_html($erpProduct !== null ? ($erpProduct->name !== '' ? $erpProduct->name : $externalId) : $externalId); ?></h1>
                </div>
            </header>

            <?php if ($saved) : ?><div class="rc-portal-alert rc-portal-alert--success"><?php esc_html_e('Fiche enregistrée.', 'rc-portal'); ?></div><?php endif; ?>
            <?php if ($warning !== null) : ?><div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($warning); ?></div><?php endif; ?>
            <?php if ($error !== null) : ?><div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($error); ?></div><?php endif; ?>
            <?php if ($erpError !== null) : ?><div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($erpError); ?></div><?php endif; ?>

            <?php echo $this->renderErpCard($erpProduct); ?>

            <?php if ($record === null) : ?>
                <div class="rc-card">
                    <div class="rc-card__header"><h3><?php esc_html_e('Réconciliation', 'rc-portal'); ?></h3></div>
                    <?php if ($canEdit) : ?>
                        <form method="post">
                            <input type="hidden" name="rc_products_action" value="reconcile">
                            <input type="hidden" name="rc_products_nonce" value="<?php echo esc_attr(wp_create_nonce('rc_products_reconcile_' . $externalId)); ?>">
                            <div class="rc-field-grid">
                                <div class="rc-field">
                                    <label><?php esc_html_e('Typologie (optionnel — peut être classée plus tard)', 'rc-portal'); ?></label>
                                    <select name="family">
                                        <option value=""><?php esc_html_e('Non classé pour l’instant', 'rc-portal'); ?></option>
                                        <?php foreach (ProductFamilies::all() as $slug => $definition) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($definition['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="rc-field">
                                    <label><?php esc_html_e('Fabricant (optionnel)', 'rc-portal'); ?></label>
                                    <select name="manufacturer_uid">
                                        <option value=""><?php esc_html_e('—', 'rc-portal'); ?></option>
                                        <?php foreach ($manufacturers as $manufacturer) : ?>
                                            <option value="<?php echo esc_attr($manufacturer->uid); ?>"><?php echo esc_html($manufacturer->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Créer la fiche RC', 'rc-portal'); ?></button>
                        </form>
                    <?php else : ?>
                        <p><?php esc_html_e('Accès non autorisé pour réconcilier ce produit.', 'rc-portal'); ?></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="rc-card">
                    <div class="rc-card__header"><h3><?php esc_html_e('Fiche RC — à classifier', 'rc-portal'); ?></h3></div>
                    <form method="post">
                        <input type="hidden" name="rc_products_action" value="enrich">
                        <input type="hidden" name="rc_products_nonce" value="<?php echo esc_attr(wp_create_nonce('rc_products_enrich_' . $record->uid)); ?>">
                        <div class="rc-field-grid">
                            <div class="rc-field">
                                <label><?php esc_html_e('Typologie', 'rc-portal'); ?></label>
                                <select name="family" data-rc-family-toggle <?php disabled(! $canEdit); ?>>
                                    <option value=""><?php esc_html_e('Non classé pour l’instant', 'rc-portal'); ?></option>
                                    <?php foreach (ProductFamilies::all() as $slug => $definition) : ?>
                                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($definition['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="rc-field">
                                <label><?php esc_html_e('Fabricant', 'rc-portal'); ?></label>
                                <select name="manufacturer_uid" <?php disabled(! $canEdit); ?>>
                                    <option value=""><?php esc_html_e('—', 'rc-portal'); ?></option>
                                    <?php foreach ($manufacturers as $manufacturer) : ?>
                                        <option value="<?php echo esc_attr($manufacturer->uid); ?>" <?php selected($record->manufacturerUid, $manufacturer->uid); ?>>
                                            <?php echo esc_html($manufacturer->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php echo $this->renderI18nFields($record->i18n, $canEdit); ?>
                        <?php echo $this->renderSpecsFields((string) $record->family, $record->specs, $record->fiche, $canEdit); ?>

                        <?php if ($canEdit) : ?>
                            <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Enregistrer', 'rc-portal'); ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -- Per-family pages ------------------------------------------------

    public function renderFamily(object $context): string
    {
        $family = $this->familyFromRoute($context);
        if ($family === null) {
            return $this->notFound(__('Typologie de produit inconnue.', 'rc-portal'));
        }

        $uid = trim((string) ($context->remainder ?? ''), '/');

        return $uid !== '' ? $this->renderFamilyFiche($family, $uid) : $this->renderFamilyList($family);
    }

    private function renderFamilyList(string $family): string
    {
        $records = $this->repository->list($family, 200);

        $provider = function_exists('rc_core') ? rc_core()->erp()->activeSource() : 'axonaut';
        $erpProducts = [];
        if ($records !== []) {
            $externalIds = array_map(static fn (ProductRecord $record): string => $record->erpExternalId, $records);
            try {
                $erpProducts = $this->erpProvider()->findMany($externalIds);
            } catch (\Throwable $exception) {
                $erpProducts = [];
            }
        }

        ob_start();
        ?>
        <div class="rc-products-family-list">
            <div class="rc-list-toolbar-actions">
                <span class="rc-muted">
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of fiches */
                        _n('%d fiche', '%d fiches', count($records), 'rc-portal'),
                        count($records)
                    )); ?>
                </span>
                <a class="rc-button" href="<?php echo esc_url(home_url('/products/catalogue/')); ?>">
                    <?php esc_html_e('Réconcilier depuis le catalogue', 'rc-portal'); ?>
                </a>
            </div>

            <?php if ($records === []) : ?>
                <div class="rc-empty">
                    <strong><?php esc_html_e('Aucune fiche produit pour cette typologie.', 'rc-portal'); ?></strong>
                </div>
            <?php else : ?>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Référence ERP', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Désignation', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Statut', 'rc-portal'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record) : ?>
                                <?php $erpProduct = $erpProducts[$record->erpExternalId] ?? null; ?>
                                <tr>
                                    <td><?php echo esc_html($erpProduct !== null ? $erpProduct->productCode : $record->erpExternalId); ?></td>
                                    <td>
                                        <?php
                                        $designation = $record->designation();
                                        if ($designation === '' && $erpProduct !== null) {
                                            $designation = $erpProduct->name;
                                        }
                                        echo esc_html($designation);
                                        ?>
                                    </td>
                                    <td>
                                        <span class="rc-badge <?php echo $record->isActive() ? 'rc-badge--success' : 'rc-badge--muted'; ?>">
                                            <?php echo esc_html($record->isActive() ? __('Actif', 'rc-portal') : __('Archivé', 'rc-portal')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(home_url('/products/' . $family . '/' . rawurlencode($record->uid) . '/')); ?>">
                                            <?php esc_html_e('Ouvrir', 'rc-portal'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function renderFamilyFiche(string $family, string $uid): string
    {
        $record = $this->repository->findByUid($uid);
        if ($record === null || $record->family !== $family) {
            return $this->notFound(__('Fiche produit introuvable.', 'rc-portal'));
        }

        $canEdit = current_user_can('rc_products_edit');
        $error = null;
        $saved = false;

        $erpProduct = null;
        $erpError = null;
        try {
            $erpProduct = $this->erpProvider()->find($record->erpExternalId);
        } catch (\Throwable $exception) {
            $erpError = __('Connexion au catalogue Axonaut indisponible pour le moment.', 'rc-portal');
        }

        if ($canEdit && $this->isPost('save')) {
            if (! wp_verify_nonce($this->postValue('rc_products_nonce'), 'rc_products_save_' . $record->uid)) {
                $error = __('La demande a expiré, veuillez réessayer.', 'rc-portal');
            } else {
                try {
                    $manufacturerUid = $this->postValue('manufacturer_uid');
                    // Status is not a local editorial field: Axonaut is the
                    // source of truth for whether a product is active, so
                    // it's derived from the ERP record's own `disabled` flag
                    // on every save rather than taken from user input.
                    $status = ($erpProduct !== null && $erpProduct->disabled) ? 'archived' : 'active';
                    $newFamily = sanitize_key($this->postValue('family', $family));

                    $this->repository->saveCommon($record->postId, $manufacturerUid !== '' ? $manufacturerUid : null, $status);
                    $this->repository->saveI18n($record->postId, $this->i18nInputFromRequest());
                    $this->repository->saveSpecs($record->postId, $this->specsInputFromRequest());

                    $familyChanged = $newFamily !== $family && ProductFamilies::isValid($newFamily);
                    if ($familyChanged) {
                        // Reassign first: saveFiche() below normalizes against
                        // whatever family the record carries right now, so the
                        // typology must already be the new one before it runs
                        // — otherwise the fields the dynamic panel just showed
                        // (for the new typology) would be normalized against
                        // the old schema and silently dropped.
                        $this->repository->setFamily($record->postId, $newFamily);
                    }

                    // Interpret the submitted fiche fields against whichever
                    // typology block the form actually showed (the new one,
                    // when it was just switched via the dynamic panel), so a
                    // reclassification + fiche edit submitted together lands
                    // correctly in one go.
                    $this->repository->saveFiche($record->postId, $this->familyFieldsInputFromRequest($familyChanged ? $newFamily : $family));

                    if ($familyChanged) {
                        wp_safe_redirect(home_url('/products/' . $newFamily . '/' . rawurlencode($record->uid) . '/'));
                        exit;
                    }

                    $record = $this->repository->find($record->postId) ?? $record;
                    $saved = true;
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        $manufacturers = function_exists('rc_core') ? rc_core()->manufacturers()->all() : [];

        // The native ERP name and SKU (product_code) are the authoritative
        // product identity — the local "projection" designation is a
        // WooCommerce-facing override, not the product's name — so they lead
        // the title/eyebrow whenever Axonaut is reachable.
        $title = ($erpProduct !== null && $erpProduct->name !== '')
            ? $erpProduct->name
            : ($record->designation() !== '' ? $record->designation() : $record->erpExternalId);
        $sku = ($erpProduct !== null && $erpProduct->productCode !== '') ? $erpProduct->productCode : $record->erpExternalId;

        ob_start();
        ?>
        <div class="rc-products-fiche">
            <header class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php echo esc_html($sku); ?></span>
                    <h1><?php echo esc_html($title); ?></h1>
                </div>
            </header>

            <?php if ($saved) : ?><div class="rc-portal-alert rc-portal-alert--success"><?php esc_html_e('Fiche enregistrée.', 'rc-portal'); ?></div><?php endif; ?>
            <?php if ($error !== null) : ?><div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($error); ?></div><?php endif; ?>
            <?php if ($erpError !== null) : ?><div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($erpError); ?></div><?php endif; ?>

            <?php echo $this->renderErpCard($erpProduct); ?>

            <form method="post">
                <input type="hidden" name="rc_products_action" value="save">
                <input type="hidden" name="rc_products_nonce" value="<?php echo esc_attr(wp_create_nonce('rc_products_save_' . $record->uid)); ?>">

                <div class="rc-card">
                    <div class="rc-card__header"><h3><?php esc_html_e('Identification', 'rc-portal'); ?></h3></div>
                    <div class="rc-field-grid">
                        <div class="rc-field">
                            <label><?php esc_html_e('Typologie', 'rc-portal'); ?></label>
                            <select name="family" data-rc-family-toggle <?php disabled(! $canEdit); ?>>
                                <?php foreach (ProductFamilies::all() as $slug => $definition) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($family, $slug); ?>>
                                        <?php echo esc_html($definition['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rc-field">
                            <label><?php esc_html_e('Fabricant', 'rc-portal'); ?></label>
                            <select name="manufacturer_uid" <?php disabled(! $canEdit); ?>>
                                <option value=""><?php esc_html_e('—', 'rc-portal'); ?></option>
                                <?php foreach ($manufacturers as $manufacturer) : ?>
                                    <option value="<?php echo esc_attr($manufacturer->uid); ?>" <?php selected($record->manufacturerUid, $manufacturer->uid); ?>>
                                        <?php echo esc_html($manufacturer->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <?php echo $this->renderI18nFields($record->i18n, $canEdit); ?>
                <?php echo $this->renderSpecsFields($family, $record->specs, $record->fiche, $canEdit); ?>

                <?php if ($canEdit) : ?>
                    <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Enregistrer', 'rc-portal'); ?></button>
                <?php endif; ?>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -- Shared field blocks ---------------------------------------------

    private function renderErpCard(?ProductData $erpProduct): string
    {
        if ($erpProduct === null) {
            return '';
        }

        ob_start();
        ?>
        <div class="rc-card">
            <div class="rc-card__header">
                <h3><?php esc_html_e('Données Axonaut (lecture seule)', 'rc-portal'); ?></h3>
                <div class="rc-card__header-actions">
                    <span class="rc-badge"><?php echo esc_html($erpProduct->productCode); ?></span>
                    <?php if ($erpProduct->disabled) : ?>
                        <span class="rc-badge rc-badge--muted"><?php esc_html_e('Désactivé', 'rc-portal'); ?></span>
                    <?php else : ?>
                        <span class="rc-badge rc-badge--success"><?php esc_html_e('Actif', 'rc-portal'); ?></span>
                    <?php endif; ?>
                    <?php if ($erpProduct->imageUrl !== '') : ?>
                        <a class="rc-badge rc-badge--accent" href="<?php echo esc_url($erpProduct->imageUrl); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Voir l’image', 'rc-portal'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rc-erp-columns">
                <?php foreach (self::ERP_FIELD_GROUPS as $groupLabel => $fields) : ?>
                    <div class="rc-erp-column">
                        <span class="rc-eyebrow"><?php echo esc_html($groupLabel); ?></span>
                        <?php foreach ($fields as $property => $label) : ?>
                            <?php $value = $erpProduct->{$property}; ?>
                            <?php if ($value === null || $value === '') {
                                continue;
                            } ?>
                            <div class="rc-field">
                                <span><?php echo esc_html($label); ?></span>
                                <strong><?php echo esc_html(is_float($value) ? number_format_i18n($value, 2) : (string) $value); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($erpProduct->description !== '') : ?>
                <div class="rc-erp-description">
                    <span><?php esc_html_e('Description (Axonaut)', 'rc-portal'); ?></span>
                    <div class="rc-erp-description__body">
                        <?php echo wp_kses($erpProduct->description, self::ERP_DESCRIPTION_ALLOWED_HTML); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function renderI18nFields(array $i18n, bool $canEdit): string
    {
        $locales = ProductTranslations::allowedLocales();

        if ($canEdit && function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }

        ob_start();
        ?>
        <div class="rc-card">
            <div class="rc-card__header"><h3><?php esc_html_e('Projection WooCommerce', 'rc-portal'); ?></h3></div>
            <div class="rc-tabs" data-rc-tabs>
                <div class="rc-tabs__nav" role="tablist">
                    <?php foreach ($locales as $index => $locale) : ?>
                        <button type="button" class="rc-tab" data-rc-tab="<?php echo esc_attr($locale); ?>"
                                role="tab" aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                            <?php echo esc_html(strtoupper($locale)); ?>
                            <?php echo $locale === 'fr' ? ' (' . esc_html__('natif', 'rc-portal') . ')' : ''; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($locales as $index => $locale) : ?>
                    <?php
                    $entry = $i18n[$locale] ?? ['designation' => '', 'description' => ''];
                    $editorId = 'rc_i18n_desc_' . $locale;
                    $fieldName = 'i18n_' . $locale . '_description';
                    ?>
                    <div class="rc-tabpanel" data-rc-tabpanel="<?php echo esc_attr($locale); ?>" role="tabpanel" <?php echo $index === 0 ? '' : 'hidden'; ?>>
                        <div class="rc-field">
                            <label>
                                <?php echo esc_html(strtoupper($locale)); ?> —
                                <?php esc_html_e('Désignation', 'rc-portal'); ?>
                            </label>
                            <input type="text" name="i18n_<?php echo esc_attr($locale); ?>_designation"
                                   value="<?php echo esc_attr($entry['designation']); ?>" <?php disabled(! $canEdit); ?>>
                        </div>
                        <div class="rc-field">
                            <label><?php echo esc_html(strtoupper($locale)); ?> — <?php esc_html_e('Description', 'rc-portal'); ?></label>
                            <?php if ($canEdit && function_exists('wp_editor') && $index === 0) : ?>
                                <?php
                                wp_editor($entry['description'], $editorId, [
                                    'textarea_name' => $fieldName,
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny' => true,
                                    // No quicktags: the raw-HTML "Texte" tab
                                    // is hidden, this stays a pure visual editor.
                                    'quicktags' => false,
                                    'tinymce' => ['wpautop' => true, 'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo'],
                                ]);
                                ?>
                            <?php elseif ($canEdit) : ?>
                                <textarea id="<?php echo esc_attr($editorId); ?>" class="rc-wysiwyg-lazy" name="<?php echo esc_attr($fieldName); ?>" rows="8"><?php
                                    echo esc_textarea($entry['description']);
                                ?></textarea>
                            <?php else : ?>
                                <textarea name="<?php echo esc_attr($fieldName); ?>" rows="8" disabled><?php
                                    echo esc_textarea($entry['description']);
                                ?></textarea>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * "Caractéristiques locales" is a single card whose content is
     * typology-dependent — it's not a separate concept from the family's
     * specific fields, just the same card wearing different fields per
     * typology: an asset pointer for robot/cellule, the dimension/customs
     * fields for a pièce, and a raw-JSON fallback for the families whose
     * schema isn't cadré yet. Every variant is rendered at once, tagged with
     * the families it applies to via `data-rc-family-fields` (a CSV of
     * slugs); `portal.js` toggles their `hidden` attribute when the
     * Typologie select changes, so switching typology "permutes" the
     * visible fields without a page reload. Only the block matching the
     * fiche's *current* family starts visible.
     */
    private function renderSpecsFields(string $family, array $specs, array $fiche, bool $canEdit): string
    {
        $assetFamilies = ['robot', 'cellule'];
        $dimensionFamilies = ['piece'];
        $openFamilies = ['maintenance', 'logistique', 'deplacement', 'consommable'];

        ob_start();
        ?>
        <div class="rc-card">
            <div class="rc-card__header"><h3><?php esc_html_e('Caractéristiques locales', 'rc-portal'); ?></h3></div>

            <div class="rc-family-fields" data-rc-family-fields="<?php echo esc_attr(implode(',', $assetFamilies)); ?>" <?php echo in_array($family, $assetFamilies, true) ? '' : 'hidden'; ?>>
                <div class="rc-field-grid">
                    <div class="rc-field">
                        <label><?php esc_html_e('Asset lié (Maintenance)', 'rc-portal'); ?></label>
                        <input type="text" name="asset_uid"
                               value="<?php echo esc_attr((string) ($fiche['assetUid'] ?? '')); ?>" <?php disabled(! $canEdit); ?>>
                        <span class="rc-field--help">
                            <?php esc_html_e('Simple référence vers la fiche Asset que Maintenance possédera (propriétaire, site, configuration, historique). Laisser vide tant que Maintenance n’est pas livré.', 'rc-portal'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="rc-family-fields" data-rc-family-fields="<?php echo esc_attr(implode(',', $dimensionFamilies)); ?>" <?php echo in_array($family, $dimensionFamilies, true) ? '' : 'hidden'; ?>>
                <div class="rc-field-grid">
                    <div class="rc-field">
                        <label><?php esc_html_e('Longueur (mm)', 'rc-portal'); ?></label>
                        <input type="number" step="0.1" min="0" name="longueur_mm"
                               value="<?php echo esc_attr((string) ($specs['longueurMm'] ?? '')); ?>" <?php disabled(! $canEdit); ?>>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('Largeur (mm)', 'rc-portal'); ?></label>
                        <input type="number" step="0.1" min="0" name="largeur_mm"
                               value="<?php echo esc_attr((string) ($specs['largeurMm'] ?? '')); ?>" <?php disabled(! $canEdit); ?>>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('Profondeur (mm)', 'rc-portal'); ?></label>
                        <input type="number" step="0.1" min="0" name="profondeur_mm"
                               value="<?php echo esc_attr((string) ($specs['profondeurMm'] ?? '')); ?>" <?php disabled(! $canEdit); ?>>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('Code douanier', 'rc-portal'); ?></label>
                        <input type="text" name="tariff_code"
                               value="<?php echo esc_attr((string) ($specs['tariffCode'] ?? '')); ?>" <?php disabled(! $canEdit); ?>>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('Pays d’origine', 'rc-portal'); ?></label>
                        <input type="text" name="country_of_origin"
                               value="<?php echo esc_attr((string) ($specs['countryOfOrigin'] ?? '')); ?>" <?php disabled(! $canEdit); ?>>
                    </div>
                </div>
            </div>

            <div class="rc-family-fields" data-rc-family-fields="<?php echo esc_attr(implode(',', $openFamilies)); ?>" <?php echo in_array($family, $openFamilies, true) ? '' : 'hidden'; ?>>
                <div class="rc-field">
                    <label><?php esc_html_e('Enrichissement (typologie non encore cadrée)', 'rc-portal'); ?></label>
                    <textarea name="fiche_json" rows="6" <?php disabled(! $canEdit); ?>><?php
                        echo esc_textarea(in_array($family, $openFamilies, true) ? (string) wp_json_encode($fiche, JSON_PRETTY_PRINT) : '{}');
                    ?></textarea>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -- Request parsing ---------------------------------------------------

    /** @return array<string,mixed> */
    private function i18nInputFromRequest(): array
    {
        $input = [];
        foreach (ProductTranslations::allowedLocales() as $locale) {
            $input[$locale] = [
                'designation' => $this->postValue('i18n_' . $locale . '_designation'),
                'description' => $this->postValue('i18n_' . $locale . '_description'),
            ];
        }

        return $input;
    }

    /** @return array<string,mixed> */
    private function specsInputFromRequest(): array
    {
        return [
            'longueurMm' => $this->postValue('longueur_mm'),
            'largeurMm' => $this->postValue('largeur_mm'),
            'profondeurMm' => $this->postValue('profondeur_mm'),
            'tariffCode' => $this->postValue('tariff_code'),
            'countryOfOrigin' => $this->postValue('country_of_origin'),
        ];
    }

    /** @return array<string,mixed> */
    private function familyFieldsInputFromRequest(string $family): array
    {
        return match ($family) {
            'robot', 'cellule' => ['assetUid' => $this->postValue('asset_uid')],
            default => (static function (string $json): array {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            })($this->postValue('fiche_json', '{}')),
        };
    }

    private function familyFromRoute(object $context): ?string
    {
        $segments = explode('/', trim((string) ($context->route ?? ''), '/'));
        $slug = $segments[1] ?? '';

        return ProductFamilies::isValid($slug) ? $slug : null;
    }

    private function notFound(string $message): string
    {
        return '<div class="rc-empty"><strong>' . esc_html($message) . '</strong></div>';
    }

    private function erpProvider(): ProductProviderInterface
    {
        return rc_core()->erp()->get(ProductProviderInterface::class);
    }

    private function isPost(string $action): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['rc_products_action'])
            && $_POST['rc_products_action'] === $action;
    }

    private function postValue(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : $default;
    }
}
