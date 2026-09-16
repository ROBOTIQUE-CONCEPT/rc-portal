<?php

declare(strict_types=1);

namespace RC\Portal\Module;

/**
 * Contract for business modules embedded inside RC Portal.
 *
 * This is a Portal composition contract, not a replacement for the RC Core
 * platform module API. RC Portal itself remains the deployable Core module.
 */
interface EmbeddedModuleInterface
{
    public function descriptor(): ModuleDescriptor;

    /** Register services/hooks without executing request-specific work. */
    public function register(): void;

    /** Boot the module after all embedded modules have been registered. */
    public function boot(): void;
}
