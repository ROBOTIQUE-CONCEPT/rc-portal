<?php

declare(strict_types=1);

namespace RC\Portal\Module;

use RuntimeException;

/**
 * Catalog of modules physically embedded in the RC Portal distribution.
 *
 * It does not provide cross-module services. Modules remain isolated and must
 * collaborate only through RC Core contracts/events.
 */
final class ModuleCatalog
{
    /** @var array<string, EmbeddedModuleInterface> */
    private array $modules = [];

    public function loadFromDirectory(string $directory): void
    {
        $files = glob(trailingslashit($directory) . '*/module.php') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $module = require $file;
            if (! $module instanceof EmbeddedModuleInterface) {
                throw new RuntimeException(sprintf('Invalid RC Portal module bootstrap: %s', $file));
            }
            $this->add($module);
        }
    }

    public function add(EmbeddedModuleInterface $module): void
    {
        $id = sanitize_key($module->descriptor()->id);
        if ($id === '' || $id !== $module->descriptor()->id) {
            throw new RuntimeException('Embedded module IDs must be canonical sanitize_key values.');
        }
        if (isset($this->modules[$id])) {
            throw new RuntimeException(sprintf('Duplicate RC Portal module ID: %s', $id));
        }
        $this->modules[$id] = $module;
    }

    public function registerAll(): void
    {
        foreach ($this->modules as $module) {
            $module->register();
        }
    }

    public function bootAll(): void
    {
        foreach ($this->modules as $module) {
            $module->boot();
        }
    }

    public function get(string $id): ?EmbeddedModuleInterface
    {
        return $this->modules[sanitize_key($id)] ?? null;
    }

    /** @return list<EmbeddedModuleInterface> */
    public function all(): array
    {
        $modules = array_values($this->modules);
        usort(
            $modules,
            static fn (EmbeddedModuleInterface $a, EmbeddedModuleInterface $b): int =>
                $a->descriptor()->order <=> $b->descriptor()->order
        );
        return $modules;
    }
}
