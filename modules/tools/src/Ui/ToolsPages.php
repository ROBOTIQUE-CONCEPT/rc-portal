<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\Ui;

use RC\Portal\Modules\Tools\KukaArchive\KukaArchiveAnalyzer;
use RC\Portal\Modules\Tools\KukaArchive\KukaArchiveReport;
use RC\Portal\Modules\Tools\KukaArchive\MessageLogAjaxHandler;
use RC\Portal\Modules\Tools\KukaArchive\MessageLogProcessor;

defined('ABSPATH') || exit;

/**
 * Renders the `tools` module's RC Portal pages. Each tool is independent —
 * this class hosts their forms/reports side by side but shares no domain
 * logic between them (see ToolsModule's class docblock).
 */
final class ToolsPages
{
    /**
     * Application-level ceiling on top of `wp_max_upload_size()` — a real
     * KRC archive is a few MB; this stays generous while keeping a hard cap
     * regardless of how a given host's PHP is configured.
     */
    private const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

    // -- Dashboard -------------------------------------------------------

    public function renderDashboard(object $context): string
    {
        ob_start();
        ?>
        <div class="rc-tools-dashboard">
            <header class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Outils internes', 'rc-portal'); ?></span>
                    <h1><?php esc_html_e('Outils internes', 'rc-portal'); ?></h1>
                    <p><?php esc_html_e('Une collection d’outils techniques ajoutés au fil de l’eau, sans lien avec l’ERP ou les données métier.', 'rc-portal'); ?></p>
                </div>
            </header>

            <div class="rc-module-grid rc-module-grid--square">
                <a class="rc-module-card" href="<?php echo esc_url(home_url('/tools/kuka-archive/')); ?>">
                    <div class="rc-module-card__top">
                        <span class="rc-module-card__icon" aria-hidden="true">K</span>
                        <span class="rc-module-card__arrow" aria-hidden="true">↗</span>
                    </div>
                    <div class="rc-module-card__body">
                        <span class="rc-module-card__eyebrow"><?php esc_html_e('Outil', 'rc-portal'); ?></span>
                        <strong><?php esc_html_e("Analyseur d'archive KUKA", 'rc-portal'); ?></strong>
                        <p><?php esc_html_e('Charge une archive de sauvegarde KUKA (.zip) et en extrait les informations utiles au diagnostic (versions, mastering, erreurs, calibration).', 'rc-portal'); ?></p>
                    </div>
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -- KUKA archive analyzer -------------------------------------------

    public function renderKukaArchive(object $context): string
    {
        $error = null;
        $report = null;

        if ($this->isPost('analyze')) {
            if (! wp_verify_nonce($this->postValue('rc_tools_nonce'), 'rc_tools_kuka_archive')) {
                $error = __('La demande a expiré, veuillez réessayer.', 'rc-portal');
            } else {
                [$report, $error] = $this->handleKukaUpload();
            }
        }

        if ($report !== null && $report->messageLogs['databases'] !== []) {
            $this->enqueueMessageLogAssets();
        }

        ob_start();
        ?>
        <div class="rc-tools-kuka-archive">
            <header class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Outils internes', 'rc-portal'); ?></span>
                    <h1><?php esc_html_e("Analyseur d'archive KUKA", 'rc-portal'); ?></h1>
                    <p><?php esc_html_e('Charge une archive de sauvegarde KUKA Archive Manager (.zip) pour en extraire les informations utiles au diagnostic. Rien n’est conservé au-delà de cette analyse : le fichier est traité en mémoire puis supprimé à la fin de la requête ; les journaux de messages, eux, sont lus directement dans votre navigateur.', 'rc-portal'); ?></p>
                </div>
                <?php if ($report !== null) : ?>
                    <div>
                        <a class="rc-button" href="<?php echo esc_url(home_url('/tools/kuka-archive/')); ?>">
                            <?php esc_html_e('Nouvelle analyse', 'rc-portal'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </header>

            <?php if ($error !== null) : ?>
                <div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <?php if ($report === null) : ?>
                <div class="rc-card">
                    <div class="rc-card__header"><h3><?php esc_html_e('Charger une archive', 'rc-portal'); ?></h3></div>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="rc_tools_action" value="analyze">
                        <input type="hidden" name="rc_tools_nonce" value="<?php echo esc_attr(wp_create_nonce('rc_tools_kuka_archive')); ?>">
                        <div class="rc-field">
                            <label for="rc-tools-kuka-file"><?php esc_html_e('Archive (.zip)', 'rc-portal'); ?></label>
                            <input type="file" id="rc-tools-kuka-file" name="archive" accept=".zip" required>
                            <p class="rc-field--help">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: maximum upload size, formatted (e.g. "64 MB") */
                                    __('Taille maximale : %s.', 'rc-portal'),
                                    size_format(self::maxUploadBytes())
                                )); ?>
                            </p>
                        </div>
                        <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Analyser', 'rc-portal'); ?></button>
                    </form>
                </div>
            <?php else : ?>
                <?php echo $this->renderKukaReport($report); ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @return array{0:?KukaArchiveReport,1:?string} */
    private function handleKukaUpload(): array
    {
        if (! isset($_FILES['archive']) || ! is_array($_FILES['archive'])) {
            return [null, __('Aucun fichier reçu.', 'rc-portal')];
        }

        $file = $_FILES['archive'];
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return [null, __('Aucun fichier sélectionné.', 'rc-portal')];
        }
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            return [null, __('Le fichier dépasse la taille maximale autorisée.', 'rc-portal')];
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            return [null, __('Le téléversement a échoué, veuillez réessayer.', 'rc-portal')];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || ! is_uploaded_file($tmpName)) {
            return [null, __('Fichier temporaire invalide.', 'rc-portal')];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return [null, __('Le fichier est vide.', 'rc-portal')];
        }
        if ($size > self::maxUploadBytes()) {
            return [null, __('Le fichier dépasse la taille maximale autorisée.', 'rc-portal')];
        }

        $originalName = sanitize_file_name((string) ($file['name'] ?? 'archive.zip'));
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            return [null, __('Seules les archives .zip sont acceptées.', 'rc-portal')];
        }

        try {
            $report = KukaArchiveAnalyzer::analyze($tmpName, $originalName, $size);
        } finally {
            // Explicit safety net: the archive was never moved out of PHP's
            // own upload temp path (no wp_handle_upload() call), so this
            // unlink — on top of PHP's own end-of-request cleanup — is what
            // guarantees nothing but the in-memory report below survives
            // the request. Nothing is ever written to WordPress's uploads
            // directory or any datastore.
            @unlink($tmpName);
        }

        return [$report, null];
    }

    private static function maxUploadBytes(): int
    {
        return min(self::MAX_UPLOAD_BYTES, wp_max_upload_size());
    }

    /**
     * Loads the bundled message-log reader JS (see
     * assets/js-src/message-logs.js — `mdb-reader` for the Jet/Access
     * format, a hand-written parser for the classic Windows Event Log one)
     * — only on this page, and only when the archive actually contains a
     * message-log file for it to read.
     */
    private function enqueueMessageLogAssets(): void
    {
        wp_enqueue_script(
            'rc-tools-message-log',
            plugins_url('modules/tools/assets/js/message-logs.bundle.js', RC_PORTAL_FILE),
            [],
            RC_PORTAL_VERSION,
            true
        );
    }

    private function renderKukaReport(KukaArchiveReport $report): string
    {
        ob_start();
        ?>
        <?php foreach ($report->warnings as $warning) : ?>
            <div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($warning); ?></div>
        <?php endforeach; ?>

        <div class="rc-tabs" data-rc-tabs>
            <div class="rc-tabs__nav" role="tablist">
                <button type="button" class="rc-tab" data-rc-tab="resume" role="tab" aria-selected="true"><?php esc_html_e('Résumé', 'rc-portal'); ?></button>
                <button type="button" class="rc-tab" data-rc-tab="calibration" role="tab" aria-selected="false"><?php esc_html_e('Calibration', 'rc-portal'); ?></button>
                <button type="button" class="rc-tab" data-rc-tab="bases-outils" role="tab" aria-selected="false"><?php esc_html_e('Bases & Outils', 'rc-portal'); ?></button>
                <button type="button" class="rc-tab" data-rc-tab="signaux" role="tab" aria-selected="false"><?php esc_html_e('Signaux', 'rc-portal'); ?></button>
                <button type="button" class="rc-tab" data-rc-tab="logs" role="tab" aria-selected="false"><?php esc_html_e('Logs', 'rc-portal'); ?></button>
            </div>

            <div class="rc-tabpanel" data-rc-tabpanel="resume" role="tabpanel">
                <?php echo $this->renderResumeTab($report); ?>
            </div>
            <div class="rc-tabpanel" data-rc-tabpanel="calibration" role="tabpanel" hidden>
                <?php echo $this->renderCalibrationTab($report); ?>
            </div>
            <div class="rc-tabpanel" data-rc-tabpanel="bases-outils" role="tabpanel" hidden>
                <?php echo $this->renderBasesOutilsTab($report); ?>
            </div>
            <div class="rc-tabpanel" data-rc-tabpanel="signaux" role="tabpanel" hidden>
                <?php echo $this->renderSignauxTab($report); ?>
            </div>
            <div class="rc-tabpanel" data-rc-tabpanel="logs" role="tabpanel" hidden>
                <?php echo $this->renderLogsTab($report); ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // -- Tab: Résumé -------------------------------------------------------

    private function renderResumeTab(KukaArchiveReport $report): string
    {
        $archive = $report->archive;

        ob_start();
        ?>
        <div class="rc-card">
            <div class="rc-card__header"><h3><?php esc_html_e('Résumé de l’archive', 'rc-portal'); ?></h3></div>
            <div class="rc-field-grid">
                <div class="rc-field">
                    <label><?php esc_html_e('Fichier', 'rc-portal'); ?></label>
                    <span><?php echo esc_html($report->sourceFileName); ?> (<?php echo esc_html(size_format($report->sourceSize)); ?>)</span>
                </div>
                <?php if ($archive !== null) : ?>
                    <div class="rc-field">
                        <label><?php esc_html_e('Robot (Archive Manager)', 'rc-portal'); ?></label>
                        <span><?php echo esc_html($archive['robotName'] ?? '—'); ?></span>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('N° de série (Archive Manager)', 'rc-portal'); ?></label>
                        <span><?php echo esc_html($archive['serialNumber'] ?? '—'); ?></span>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('Date de la sauvegarde', 'rc-portal'); ?></label>
                        <span><?php echo esc_html($archive['date'] ?? '—'); ?></span>
                    </div>
                    <div class="rc-field">
                        <label><?php esc_html_e('Version de l’outil Archive Manager', 'rc-portal'); ?></label>
                        <span><?php echo esc_html($archive['toolVersion'] ?? '—'); ?></span>
                        <p class="rc-field--help"><?php esc_html_e('Version de l’outil de sauvegarde lui-même — pas la version du logiciel robot (KSS), indiquée dans le tableau ci-dessous.', 'rc-portal'); ?></p>
                    </div>
                <?php endif; ?>
                <div class="rc-field">
                    <label><?php esc_html_e('Version KSS de la cellule', 'rc-portal'); ?></label>
                    <span><?php echo esc_html($report->cellVersion ?? '—'); ?></span>
                </div>
            </div>
        </div>

        <?php if ($report->robots !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php echo esc_html(count($report->robots) > 1 ? __('Robots', 'rc-portal') : __('Robot', 'rc-portal')); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Modèle', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Numéro de série', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Version KSS', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->robots as $robot) : ?>
                            <tr>
                                <td><?php echo esc_html($robot['modelName'] ?? '—'); ?></td>
                                <td><?php echo esc_html($robot['serialNumber'] ?? '—'); ?></td>
                                <td><?php echo esc_html($robot['madaVersion'] ?? $robot['robcorVersion'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->techPacks !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Options installées', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Option', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Version', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->techPacks as $techPack) : ?>
                            <tr>
                                <td><?php echo esc_html($techPack['name']); ?></td>
                                <td><?php echo esc_html($techPack['version']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->programs !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Programmes utilisateur', 'rc-portal'); ?></h3></div>
                <div class="rc-field-grid">
                    <?php foreach ($report->programs as $group) : ?>
                        <div class="rc-field">
                            <label><?php echo esc_html($group['robot']); ?></label>
                            <span><?php echo esc_html(implode(', ', $group['programs'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    // -- Tab: Calibration (mastering + xHome + .cal) ------------------------

    private function renderCalibrationTab(KukaArchiveReport $report): string
    {
        ob_start();
        ?>
        <?php if ($report->masteringEvents !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Historique de mastering', 'rc-portal'); ?></h3></div>
                <?php foreach ($report->masteringSerialChanges as $change) : ?>
                    <div class="rc-portal-alert rc-portal-alert--error">
                        <?php echo esc_html(sprintf(
                            /* translators: 1: axis number, 2: comma-separated list of serial numbers */
                            __('Axe %1$d : plusieurs numéros de série ont été mastérisés au fil du temps (%2$s) — possible remplacement moteur/résolveur, à vérifier.', 'rc-portal'),
                            $change['axis'],
                            implode(' → ', $change['serialNumbers'])
                        )); ?>
                    </div>
                <?php endforeach; ?>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Axe', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('N° de série', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Événement', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Valeur codeur', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->masteringEvents as $event) : ?>
                            <tr>
                                <td><?php echo esc_html($this->formatDateTime($event['date'])); ?></td>
                                <td><?php echo esc_html((string) $event['axis']); ?></td>
                                <td><?php echo esc_html($event['serialNumber']); ?></td>
                                <td><?php echo esc_html($event['label']); ?></td>
                                <td><?php echo esc_html($event['firstEncoderValue'] !== null ? (string) $event['firstEncoderValue'] : '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->homePositions !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Positions de référence (xHome)', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Position', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Axes', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->homePositions as $name => $axes) : ?>
                            <tr>
                                <td><span class="rc-badge"><?php echo esc_html((string) $name); ?></span></td>
                                <td><?php echo esc_html($this->formatPairs($axes)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->calibrations !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Calibration', 'rc-portal'); ?></h3></div>
                <?php foreach ($report->calibrations as $calibration) : ?>
                    <p>
                        <?php if ($calibration['hasDrift']) : ?>
                            <span class="rc-badge rc-badge--accent"><?php esc_html_e('Écarts non nuls — à vérifier', 'rc-portal'); ?></span>
                        <?php else : ?>
                            <span class="rc-badge rc-badge--success"><?php esc_html_e('Aucun écart', 'rc-portal'); ?></span>
                        <?php endif; ?>
                    </p>
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <thead>
                            <tr>
                                <th><?php esc_html_e('Axe', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Valeur codeur initiale', 'rc-portal'); ?></th>
                                <th><?php esc_html_e('Écart de calibration', 'rc-portal'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $axes = array_unique(array_merge(
                                array_keys($calibration['firstEncoderValues']),
                                array_keys($calibration['calibrationDifferences'])
                            ));
                            sort($axes);
                            ?>
                            <?php foreach ($axes as $axis) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) $axis); ?></td>
                                    <td><?php echo esc_html(isset($calibration['firstEncoderValues'][$axis]) ? (string) $calibration['firstEncoderValues'][$axis] : '—'); ?></td>
                                    <td><?php echo esc_html(isset($calibration['calibrationDifferences'][$axis]) ? (string) $calibration['calibrationDifferences'][$axis] : '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($report->masteringEvents === [] && $report->homePositions === [] && $report->calibrations === []) : ?>
            <div class="rc-empty">
                <strong><?php esc_html_e('Aucune donnée de calibration détectée', 'rc-portal'); ?></strong>
            </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    // -- Tab: Bases & Outils (bases, tools, loads, additional loads, workspaces) --

    private function renderBasesOutilsTab(KukaArchiveReport $report): string
    {
        ob_start();
        ?>
        <?php if ($report->bases['items'] !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header">
                    <h3><?php echo esc_html(sprintf(
                        /* translators: 1: configured count, 2: total declared slots */
                        __('Bases (%1$d/%2$d configurées)', 'rc-portal'),
                        count($report->bases['items']),
                        $report->bases['total']
                    )); ?></h3>
                </div>
                <?php echo $this->renderFrameTable($report->bases['items']); ?>
            </div>
        <?php endif; ?>

        <?php if ($report->tools['items'] !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header">
                    <h3><?php echo esc_html(sprintf(
                        __('Outils (%1$d/%2$d configurés)', 'rc-portal'),
                        count($report->tools['items']),
                        $report->tools['total']
                    )); ?></h3>
                </div>
                <?php echo $this->renderFrameTable($report->tools['items']); ?>
            </div>
        <?php endif; ?>

        <?php if ($report->loads['items'] !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header">
                    <h3><?php echo esc_html(sprintf(
                        __('Charges (%1$d/%2$d configurées)', 'rc-portal'),
                        count($report->loads['items']),
                        $report->loads['total']
                    )); ?></h3>
                </div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Index', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Masse (kg)', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Centre de masse', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Inertie', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->loads['items'] as $load) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $load['index']); ?></td>
                                <td><?php echo esc_html((string) $load['mass']); ?></td>
                                <td><?php echo esc_html($this->formatPairs($load['centerOfMass'])); ?></td>
                                <td><?php echo esc_html($this->formatPairs($load['inertia'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->additionalLoads !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Charges additionnelles', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Axe', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Masse (kg)', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Centre de masse', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Inertie', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->additionalLoads as $load) : ?>
                            <tr>
                                <td><?php echo esc_html('A' . $load['axis']); ?></td>
                                <td><?php echo esc_html((string) $load['mass']); ?></td>
                                <td><?php echo esc_html($this->formatPairs($load['centerOfMass'])); ?></td>
                                <td><?php echo esc_html($this->formatPairs($load['inertia'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->workspaces['items'] !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header">
                    <h3><?php echo esc_html(sprintf(
                        /* translators: 1: active count, 2: total declared envelopes */
                        __('Enveloppes (%1$d/%2$d actives)', 'rc-portal'),
                        count($report->workspaces['items']),
                        $report->workspaces['total']
                    )); ?></h3>
                </div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Index', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Nom', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Mode', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Paramètres', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->workspaces['items'] as $workspace) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $workspace['index']); ?></td>
                                <td><?php echo esc_html($workspace['name'] ?? '—'); ?></td>
                                <td><span class="rc-badge"><?php echo esc_html((string) $workspace['mode']); ?></span></td>
                                <td><?php echo esc_html($this->formatPairs($workspace['params'], ['MODE'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->bases['items'] === [] && $report->tools['items'] === [] && $report->loads['items'] === [] && $report->additionalLoads === [] && $report->workspaces['items'] === []) : ?>
            <div class="rc-empty">
                <strong><?php esc_html_e('Aucune base, outil, charge ou enveloppe configuré n’a été détecté', 'rc-portal'); ?></strong>
            </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int,array{index:int,name:?string,frame:array<string,string>}> $items
     */
    private function renderFrameTable(array $items): string
    {
        ob_start();
        ?>
        <div class="rc-table-wrap">
            <table class="rc-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('Index', 'rc-portal'); ?></th>
                    <th><?php esc_html_e('Nom', 'rc-portal'); ?></th>
                    <th><?php esc_html_e('Position', 'rc-portal'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $item['index']); ?></td>
                        <td><?php echo esc_html($item['name'] ?? '—'); ?></td>
                        <td><?php echo esc_html($this->formatPairs($item['frame'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Joins a `key=>value` struct map into a compact one-line summary
     * (`"X 1003.26 · Y -309.48 · ..."`), in the order the values were
     * parsed (matching the source file's own field order).
     *
     * @param array<string,string> $pairs
     * @param array<int,string> $exclude keys to leave out (e.g. `MODE`, shown separately)
     */
    private function formatPairs(array $pairs, array $exclude = []): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            $parts[] = $key . ' ' . $value;
        }

        return implode(' · ', $parts);
    }

    // -- Tab: Signaux --------------------------------------------------------

    private function renderSignauxTab(KukaArchiveReport $report): string
    {
        ob_start();
        ?>
        <?php if ($report->signals !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header">
                    <h3><?php echo esc_html(sprintf(
                        /* translators: %d: number of declared signals */
                        __('Signaux déclarés (%d)', 'rc-portal'),
                        count($report->signals)
                    )); ?></h3>
                </div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Nom', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Cible', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Commentaire', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->signals as $signal) : ?>
                            <tr>
                                <td><?php echo esc_html($signal['name']); ?></td>
                                <td>
                                    <?php echo esc_html($signal['target']); ?>
                                    <?php if ($signal['targetTo'] !== null) : ?>
                                        <?php echo esc_html(' → ' . $signal['targetTo']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($signal['comment'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else : ?>
            <div class="rc-empty">
                <strong><?php esc_html_e('Aucun signal détecté', 'rc-portal'); ?></strong>
            </div>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    // -- Tab: Logs (text logs + message-log databases) ------------------------

    private function renderLogsTab(KukaArchiveReport $report): string
    {
        ob_start();
        ?>
        <?php if ($report->warmStartErrors !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Erreurs de démarrage à chaud', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Raison', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Occurrences', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Première', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Dernière', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->warmStartErrors as $group) : ?>
                            <tr>
                                <td><?php echo esc_html($group['reason']); ?></td>
                                <td><span class="rc-badge"><?php echo esc_html((string) $group['count']); ?></span></td>
                                <td><?php echo esc_html($this->formatDateTime($group['firstDate'])); ?></td>
                                <td><?php echo esc_html($this->formatDateTime($group['lastDate'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->fatalErrors !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Erreurs système fatales', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Tâche', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Code erreur', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Mode', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('N° de série', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Occurrences', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Première', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Dernière', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->fatalErrors as $group) : ?>
                            <tr>
                                <td><?php echo esc_html($group['task'] ?? '—'); ?></td>
                                <td><?php echo esc_html($group['errorCode'] ?? '—'); ?></td>
                                <td><?php echo esc_html($group['mode'] ?? '—'); ?></td>
                                <td><?php echo esc_html($group['serialNumber'] ?? '—'); ?></td>
                                <td><span class="rc-badge"><?php echo esc_html((string) $group['count']); ?></span></td>
                                <td><?php echo esc_html($this->formatDateTime($group['firstDate'])); ?></td>
                                <td><?php echo esc_html($this->formatDateTime($group['lastDate'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report->debugDumps !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Vidages de débogage HMI', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Fichier', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Horodatage', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->debugDumps as $dump) : ?>
                            <tr>
                                <td><?php echo esc_html($dump['fileName']); ?></td>
                                <td><?php echo esc_html($this->formatDateTime($dump['timestamp'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php echo $this->renderMessageLogs($report); ?>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders every detected message-log file as an invisible data carrier
     * (its base64 bytes plus format/category, exactly as before) followed
     * by ONE results container. All the actual work — reading each file
     * (`mdb-reader` for `format: "jet"`, a hand-written parser for
     * `format: "evt"`), merging the bundled + any archive-embedded
     * translation dictionaries, sending everything to
     * MessageLogAjaxHandler in a single request, and rendering the merged,
     * paginated, filterable table into `[data-rc-message-log-results]` —
     * happens in assets/js-src/message-logs.js (enqueued by
     * enqueueMessageLogAssets()); this method only emits the container and
     * the data it needs. See this tool's "no persistence" note in
     * ToolsModule's docblock: the base64 blobs below only ever exist in
     * this response and the browser's own page memory, never written to
     * disk again on either side.
     *
     * For `evt` files, the category (derived from the file name — see
     * MessageLogProcessor::evtCategoryFromFileName()) is computed once
     * here and passed through via `data-category`, since the AJAX request
     * doesn't otherwise carry the file name and the filename→category
     * mapping should live in exactly one place.
     */
    private function renderMessageLogs(KukaArchiveReport $report): string
    {
        $messageLogs = $report->messageLogs;
        if ($messageLogs['databases'] === []) {
            return '';
        }

        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php?action=' . MessageLogAjaxHandler::ACTION),
            'nonce' => wp_create_nonce(MessageLogAjaxHandler::NONCE_ACTION),
            'logTables' => MessageLogProcessor::LOG_TABLES,
            'dictionaryUrl' => plugins_url('modules/tools/assets/data/kuka-message-dictionary.json', RC_PORTAL_FILE),
        ];

        ob_start();
        ?>
        <script type="application/json" id="rc-tools-message-log-config"><?php echo wp_json_encode($config); ?></script>

        <?php foreach ($messageLogs['databases'] as $database) : ?>
            <script
                type="text/plain"
                class="rc-message-log__data"
                data-filename="<?php echo esc_attr($database['fileName']); ?>"
                data-format="<?php echo esc_attr($database['format']); ?>"
                data-category="<?php echo esc_attr($database['format'] === 'evt' ? MessageLogProcessor::evtCategoryFromFileName($database['fileName']) : ''); ?>"
            ><?php echo esc_html($database['dataBase64']); ?></script>
        <?php endforeach; ?>

        <p class="rc-field--help">
            <?php echo esc_html(sprintf(
                /* translators: %s: comma-separated list of file names */
                __('Fichiers de journal détectés : %s', 'rc-portal'),
                implode(', ', array_map(static fn (array $d): string => $d['fileName'], $messageLogs['databases']))
            )); ?>
        </p>
        <div data-rc-message-log-results>
            <p class="rc-field--help"><?php esc_html_e('Analyse en cours dans le navigateur…', 'rc-portal'); ?></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function formatDateTime(?\DateTimeImmutable $date): string
    {
        return $date !== null ? $date->format('d/m/Y H:i:s') : '—';
    }

    // -- HTTP helpers ------------------------------------------------------

    private function isPost(string $action): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['rc_tools_action'])
            && $_POST['rc_tools_action'] === $action;
    }

    private function postValue(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : $default;
    }
}
