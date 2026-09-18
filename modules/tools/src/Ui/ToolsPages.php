<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\Ui;

use RC\Portal\Modules\Tools\KukaArchive\KukaArchiveAnalyzer;
use RC\Portal\Modules\Tools\KukaArchive\KukaArchiveReport;

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
    private const MAX_UPLOAD_BYTES = 80 * 1024 * 1024;

    // -- Dashboard -------------------------------------------------------

    public function renderDashboard(object $context): string
    {
        ob_start();
        ?>
        <div class="rc-tools-dashboard">
            <div class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Outils internes', 'rc-portal'); ?></span>
                    <h1><?php esc_html_e('Outils internes', 'rc-portal'); ?></h1>
                    <p><?php esc_html_e('Une collection d’outils techniques ajoutés au fil de l’eau, sans lien avec l’ERP ou les données métier.', 'rc-portal'); ?></p>
                </div>
            </div>

            <div class="rc-module-grid">
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

        ob_start();
        ?>
        <div class="rc-tools-kuka-archive">
            <div class="rc-page-header">
                <div>
                    <span class="rc-eyebrow"><?php esc_html_e('Outils internes', 'rc-portal'); ?></span>
                    <h1><?php esc_html_e("Analyseur d'archive KUKA", 'rc-portal'); ?></h1>
                    <p><?php esc_html_e('Charge une archive de sauvegarde KUKA Archive Manager (.zip) pour en extraire les informations utiles au diagnostic. Rien n’est conservé au-delà de cette analyse : le fichier n’est jamais enregistré sur le serveur, il est traité en mémoire puis supprimé à la fin de la requête.', 'rc-portal'); ?></p>
                </div>
            </div>

            <?php if ($error !== null) : ?>
                <div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

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
                                /* translators: %s: maximum upload size, formatted (e.g. "80 MB") */
                                __('Taille maximale : %s.', 'rc-portal'),
                                size_format(self::maxUploadBytes())
                            )); ?>
                        </p>
                    </div>
                    <button type="submit" class="rc-button rc-button--primary"><?php esc_html_e('Analyser', 'rc-portal'); ?></button>
                </form>
            </div>

            <?php if ($report !== null) : ?>
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

    private function renderKukaReport(KukaArchiveReport $report): string
    {
        $archive = $report->archive;

        ob_start();
        ?>
        <?php foreach ($report->warnings as $warning) : ?>
            <div class="rc-portal-alert rc-portal-alert--error"><?php echo esc_html($warning); ?></div>
        <?php endforeach; ?>

        <div class="rc-card">
            <div class="rc-card__header"><h3><?php esc_html_e('Résumé de l’archive', 'rc-portal'); ?></h3></div>
            <div class="rc-field-grid">
                <div class="rc-field">
                    <label><?php esc_html_e('Fichier', 'rc-portal'); ?></label>
                    <span><?php echo esc_html($report->sourceFileName); ?> (<?php echo esc_html(size_format($report->sourceSize)); ?>)</span>
                </div>
                <div class="rc-field">
                    <label><?php esc_html_e('Éléments dans l’archive', 'rc-portal'); ?></label>
                    <span><?php echo esc_html((string) $report->entryCount); ?></span>
                </div>
                <div class="rc-field">
                    <label><?php esc_html_e('Analysée le', 'rc-portal'); ?></label>
                    <span><?php echo esc_html($this->formatDateTime($report->generatedAt)); ?></span>
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
                        <p class="rc-field--help"><?php esc_html_e('Version de l’outil de sauvegarde lui-même — pas la version du logiciel robot (KSS), indiquée ci-dessous.', 'rc-portal'); ?></p>
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
                <div class="rc-card__header"><h3><?php esc_html_e('Robots', 'rc-portal'); ?></h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Axe', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Modèle', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Nb. d’axes', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Version KSS', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->robots as $robot) : ?>
                            <tr>
                                <td><span class="rc-badge"><?php echo esc_html($robot['axis']); ?></span></td>
                                <td><?php echo esc_html($robot['modelName'] ?? '—'); ?></td>
                                <td><?php echo esc_html($robot['numAxes'] !== null ? (string) $robot['numAxes'] : '—'); ?></td>
                                <td><?php echo esc_html($robot['madaVersion'] ?? $robot['robcorVersion'] ?? '—'); ?></td>
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
                        <strong><?php echo esc_html(sprintf(
                            /* translators: %s: robot serial number */
                            __('N° de série %s', 'rc-portal'),
                            $calibration['serialNumber']
                        )); ?></strong>
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

        <?php if ($report->unparsedDatabases !== []) : ?>
            <div class="rc-card">
                <div class="rc-card__header"><h3><?php esc_html_e('Journaux détaillés non analysés', 'rc-portal'); ?></h3></div>
                <p class="rc-field--help"><?php esc_html_e('Ces fichiers sont des bases de données Microsoft Access/Jet utilisées par KUKA pour l’historique détaillé des messages système. Leur lecture complète nécessite un composant généralement indisponible sur un hébergement web mutualisé ; cette première version se limite donc à les repérer et à en lister les propriétés, sans en extraire le contenu. Une prochaine itération pourra aller plus loin.', 'rc-portal'); ?></p>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('Fichier', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Taille', 'rc-portal'); ?></th>
                            <th><?php esc_html_e('Dernière modification', 'rc-portal'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report->unparsedDatabases as $database) : ?>
                            <tr>
                                <td><?php echo esc_html($database['fileName']); ?></td>
                                <td><?php echo esc_html(size_format($database['size'])); ?></td>
                                <td><?php echo esc_html($this->formatDateTime($database['lastModified'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
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
