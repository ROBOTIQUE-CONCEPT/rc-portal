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
    private const ERP_FIELDS = [
        'nativeType' => 'Type',
        'category' => 'Catégorie',
        'description' => 'Description (Axonaut)',
        'unit' => 'Unité',
        'price' => 'Prix HT',
        'priceWithTax' => 'Prix TTC',
        'taxRate' => 'Taux de TVA (%)',
        'ecoParticipation' => 'Éco-participation',
        'taxDeee' => 'Taxe DEEE',
        'stock' => 'Stock',
        'stockThreshold' => 'Seuil de stock',
        'weightedAverageCost' => 'Coût moyen pondéré',
        'jobCosting' => 'Coût de revient',
        'location' => 'Emplacement',
        'supplierReference' => 'Code produit fournisseur',
        'internalId' => 'Identifiant RC (internal_id)',
    ];

    public function __construct(private readonly ProductRepository $repository)
    {
    }

    // -- Dashboard -----------------------------------------------------

    public function renderDashboard(object $context): string
    {
        $counts = $this->repository->countsByFamily();
        $totalReconciled = $this->repository->countReconciled();

        ob_start();
        ?>
        <div class="rc-products-dashboard">
            <div class="rc-card-grid">
                <div class="rc-card rc-card--stat">
                    <span><?php esc_html_e('Fiches réconciliées', 'rc-portal'); ?></span>
                    <strong><?php echo esc_html((string) $totalReconciled); ?></strong>
                </div>
                <?php foreach (ProductFamilies::all() as $slug => $definition) : ?>
                    <div class="rc-card rc-card--stat">
                        <span><?php echo esc_html($definition['label']); ?></span>
                        <strong><?php echo esc_html((string) ($counts[$slug] ?? 0)); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="rc-section-heading">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Accès rapide', 'rc-portal'); ?></span>
                    <h2><?php esc_html_e('Catalogues et typologies', 'rc-portal'); ?></h2>
                </div>
            </div>

            <div class="rc-module-grid">
                <a class="rc-module-card" href="<?php echo esc_url(home_url('/products/catalogue/')); ?>">
                    <div class="rc-module-card__top">
                        <span class="rc-module-card__icon" aria-hidden="true">⌕</span>
                        <span class="rc-module-card__arrow" aria-hidden="true">↗</span>
                    </div>
                    <div class="rc-module-card__body">
                        <span class="rc-module-card__eyebrow"><?php esc_html_e('Catalogue Axonaut', 'rc-portal'); ?></span>
                        <strong><?php esc_html_e('Rechercher un produit', 'rc-portal'); ?></strong>
                        <p><?php esc_html_e('Parcourir le catalogue ERP, ouvrir une fiche dédiée et, le cas échéant, la réconcilier.', 'rc-portal'); ?></p>
                    </div>
                </a>
                <?php foreach (ProductFamilies::all() as $slug => $definition) : ?>
                    <a class="rc-module-card" href="<?php echo esc_url(home_url('/products/' . $slug . '/')); ?>">
                        <div class="rc-module-card__top">
                            <span class="rc-module-card__icon" aria-hidden="true">
                                <?php echo esc_html(mb_strtoupper(mb_substr($definition['label'], 0, 2))); ?>
                            </span>
                            <span class="rc-module-card__arrow" aria-hidden="true">↗</span>
                        </div>
                        <div class="rc-module-card__body">
                            <span class="rc-module-card__eyebrow">
                                <?php echo $definition['projected']
                                    ? esc_html__('Catalogue public', 'rc-portal')
                                    : esc_html__('Interne uniquement', 'rc-portal'); ?>
                            </span>
                            <strong><?php echo esc_html($definition['label']); ?></strong>
                            <p>
                                <?php echo esc_html(sprintf(
                                    /* translators: %d: number of fiches */
                                    _n('%d fiche', '%d fiches', $counts[$slug] ?? 0, 'rc-portal'),
                                    $counts[$slug] ?? 0
                                )); ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
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
            <div class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Axonaut', 'rc-portal'); ?></span>
                    <h1><?php esc_html_e('Catalogue', 'rc-portal'); ?></h1>
                </div>
            </div>

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
                    $status = sanitize_key($this->postValue('status', 'active'));

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
            <div class="rc-page-header">
                <div>
                    <span class="rc-eyebrow">Axonaut #<?php echo esc_html($externalId); ?></span>
                    <h1><?php echo esc_html($erpProduct !== null ? ($erpProduct->name !== '' ? $erpProduct->name : $externalId) : $externalId); ?></h1>
                </div>
            </div>

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
                                <select name="family" <?php disabled(! $canEdit); ?>>
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
                            <div class="rc-field">
                                <label><?php esc_html_e('Statut', 'rc-portal'); ?></label>
                                <select name="status" <?php disabled(! $canEdit); ?>>
                                    <option value="active" <?php selected($record->status, 'active'); ?>><?php esc_html_e('Actif', 'rc-portal'); ?></option>
                                    <option value="archived" <?php selected($record->status, 'archived'); ?>><?php esc_html_e('Archivé', 'rc-portal'); ?></option>
                                </select>
                            </div>
                        </div>

                        <?php echo $this->renderI18nFields($record->i18n, $canEdit); ?>
                        <?php echo $this->renderSpecsFields($record->specs, $canEdit); ?>

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
        $definition = ProductFamilies::all()[$family];
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
            <div class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Typologie', 'rc-portal'); ?></span>
                    <h1><?php echo esc_html($definition['label']); ?></h1>
                </div>
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

        if ($canEdit && $this->isPost('save')) {
            if (! wp_verify_nonce($this->postValue('rc_products_nonce'), 'rc_products_save_' . $record->uid)) {
                $error = __('La demande a expiré, veuillez réessayer.', 'rc-portal');
            } else {
                try {
                    $manufacturerUid = $this->postValue('manufacturer_uid');
                    $status = sanitize_key($this->postValue('status', 'active'));
                    $newFamily = sanitize_key($this->postValue('family', $family));

                    $this->repository->saveCommon($record->postId, $manufacturerUid !== '' ? $manufacturerUid : null, $status);
                    $this->repository->saveI18n($record->postId, $this->i18nInputFromRequest());
                    $this->repository->saveSpecs($record->postId, $this->specsInputFromRequest());
                    $this->repository->saveFiche($record->postId, $this->familyFieldsInputFromRequest($family));

                    if ($newFamily !== $family && ProductFamilies::isValid($newFamily)) {
                        $this->repository->setFamily($record->postId, $newFamily);
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

        $erpProduct = null;
        $erpError = null;
        try {
            $erpProduct = $this->erpProvider()->find($record->erpExternalId);
        } catch (\Throwable $exception) {
            $erpError = __('Connexion au catalogue Axonaut indisponible pour le moment.', 'rc-portal');
        }

        $manufacturers = function_exists('rc_core') ? rc_core()->manufacturers()->all() : [];

        ob_start();
        ?>
        <div class="rc-products-fiche">
            <div class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php echo esc_html($record->uid); ?></span>
                    <h1><?php echo esc_html($record->designation() !== '' ? $record->designation() : ($erpProduct->name ?? $record->erpExternalId)); ?></h1>
                </div>
            </div>

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
                            <select name="family" <?php disabled(! $canEdit); ?>>
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
                        <div class="rc-field">
                            <label><?php esc_html_e('Statut', 'rc-portal'); ?></label>
                            <select name="status" <?php disabled(! $canEdit); ?>>
                                <option value="active" <?php selected($record->status, 'active'); ?>><?php esc_html_e('Actif', 'rc-portal'); ?></option>
                                <option value="archived" <?php selected($record->status, 'archived'); ?>><?php esc_html_e('Archivé', 'rc-portal'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php echo $this->renderI18nFields($record->i18n, $canEdit); ?>
                <?php echo $this->renderSpecsFields($record->specs, $canEdit); ?>

                <div class="rc-card">
                    <div class="rc-card__header"><h3><?php echo esc_html(ProductFamilies::label($family)); ?></h3></div>
                    <?php echo $this->renderFamilyFields($family, $record->fiche, $canEdit); ?>
                </div>

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
                <span class="rc-badge"><?php echo esc_html($erpProduct->productCode); ?></span>
            </div>
            <div class="rc-field-grid">
                <?php foreach (self::ERP_FIELDS as $property => $label) : ?>
                    <?php $value = $erpProduct->{$property}; ?>
                    <?php if ($value === null || $value === '') {
                        continue;
                    } ?>
                    <div class="rc-field">
                        <span><?php echo esc_html($label); ?></span>
                        <strong><?php echo esc_html(is_float($value) ? number_format_i18n($value, 2) : (string) $value); ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if ($erpProduct->disabled) : ?>
                    <div class="rc-field">
                        <span><?php esc_html_e('Statut Axonaut', 'rc-portal'); ?></span>
                        <span class="rc-badge rc-badge--muted"><?php esc_html_e('Désactivé', 'rc-portal'); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($erpProduct->imageUrl !== '') : ?>
                    <div class="rc-field">
                        <span><?php esc_html_e('Image', 'rc-portal'); ?></span>
                        <a href="<?php echo esc_url($erpProduct->imageUrl); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Voir l’image', 'rc-portal'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function renderI18nFields(array $i18n, bool $canEdit): string
    {
        ob_start();
        ?>
        <div class="rc-card">
            <div class="rc-card__header"><h3><?php esc_html_e('Désignation & description (projection)', 'rc-portal'); ?></h3></div>
            <?php foreach (ProductTranslations::allowedLocales() as $locale) : ?>
                <?php $entry = $i18n[$locale] ?? ['designation' => '', 'description' => '']; ?>
                <div class="rc-field-grid">
                    <div class="rc-field">
                        <label>
                            <?php echo esc_html(strtoupper($locale)); ?>
                            <?php echo $locale === 'fr' ? ' — ' . esc_html__('natif', 'rc-portal') : ''; ?>
                            — <?php esc_html_e('Désignation', 'rc-portal'); ?>
                        </label>
                        <input type="text" name="i18n_<?php echo esc_attr($locale); ?>_designation"
                               value="<?php echo esc_attr($entry['designation']); ?>" <?php disabled(! $canEdit); ?>>
                    </div>
                    <div class="rc-field">
                        <label><?php echo esc_html(strtoupper($locale)); ?> — <?php esc_html_e('Description', 'rc-portal'); ?></label>
                        <textarea name="i18n_<?php echo esc_attr($locale); ?>_description" <?php disabled(! $canEdit); ?>><?php
                            echo esc_textarea($entry['description']);
                        ?></textarea>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function renderSpecsFields(array $specs, bool $canEdit): string
    {
        ob_start();
        ?>
        <div class="rc-card">
            <div class="rc-card__header"><h3><?php esc_html_e('Caractéristiques locales', 'rc-portal'); ?></h3></div>
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
        <?php
        return (string) ob_get_clean();
    }

    private function renderFamilyFields(string $family, array $fiche, bool $canEdit): string
    {
        ob_start();
        switch ($family) {
            case 'robot':
            case 'cellule':
                ?>
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
                <?php
                break;

            default:
                ?>
                <div class="rc-field">
                    <label><?php esc_html_e('Enrichissement (typologie non encore cadrée)', 'rc-portal'); ?></label>
                    <textarea name="fiche_json" rows="6" <?php disabled(! $canEdit); ?>><?php
                        echo esc_textarea((string) wp_json_encode($fiche, JSON_PRETTY_PRINT));
                    ?></textarea>
                </div>
                <?php
                break;
        }

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
