<?php

declare(strict_types=1);

namespace RC\Portal\Module;

final class ModuleDescriptor
{
    /**
     * @param list<string> $capabilities Declared capabilities owned by this domain.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $version,
        public readonly int $schemaVersion,
        public readonly string $description,
        public readonly string $icon,
        public readonly int $order,
        public readonly array $capabilities = [],
        public readonly string $requiredCapability = 'read',
        public readonly string $status = 'foundation'
    ) {
    }
}
