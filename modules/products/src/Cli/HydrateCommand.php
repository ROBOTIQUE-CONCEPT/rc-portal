<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Products\Cli;

use RC\Portal\Modules\Products\Domain\ProductRepository;
use WPRC\Core\Contracts\ERP\ProductProviderInterface;

defined('ABSPATH') || exit;

/**
 * `wp rc products hydrate` — one-shot bulk creation of the local "carrier"
 * `rc_product` CPTs for the whole Axonaut catalog (Phase 2 follow-up:
 * "un script à lancer en CLI pour 'hydrater' la table des CPT en oneshot").
 *
 * Every Axonaut product not yet reconciled gets an unclassified fiche
 * (`ProductRepository::adopt()` with `$family = null`) so it appears under
 * `/products/catalogue/` with a "à classifier" status and the strong
 * Axonaut `internal_id` link is written back immediately, instead of
 * waiting for an operator to open each one by hand. Safe to re-run:
 * already-reconciled products are skipped, never duplicated.
 */
final class HydrateCommand
{
    public function __construct(private readonly ProductRepository $repository)
    {
    }

    /**
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (! function_exists('rc_core')) {
            \WP_CLI::error('RC Core is not available.');
            return;
        }

        $provider = rc_core()->erp()->activeSource();
        $limit = isset($assocArgs['limit']) ? max(1, (int) $assocArgs['limit']) : 5000;
        $includeDisabled = ! empty($assocArgs['include-disabled']);

        try {
            $products = rc_core()->erp()->get(ProductProviderInterface::class)->all($includeDisabled, $limit);
        } catch (\Throwable $exception) {
            \WP_CLI::error('Impossible de contacter Axonaut : ' . $exception->getMessage());
            return;
        }

        if ($products === []) {
            \WP_CLI::success('Aucun produit Axonaut à hydrater.');
            return;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Hydratation des fiches RC', count($products));

        foreach ($products as $product) {
            $progress->tick();

            if ($this->repository->findByErpLink($provider, $product->externalId) !== null) {
                $skipped++;
                continue;
            }

            $title = $product->productCode !== '' ? $product->productCode : $product->name;

            try {
                $result = $this->repository->adopt($provider, $product->externalId, null, null, $title);
                $created++;
                if ($result->warning !== null) {
                    \WP_CLI::warning(sprintf('%s — %s', $product->externalId, $result->warning));
                }
            } catch (\Throwable $exception) {
                $failed++;
                \WP_CLI::warning(sprintf('%s — %s', $product->externalId, $exception->getMessage()));
            }
        }

        $progress->finish();

        \WP_CLI::success(sprintf(
            'Terminé — %d fiche(s) créée(s), %d déjà réconciliée(s), %d échec(s).',
            $created,
            $skipped,
            $failed
        ));
    }
}
