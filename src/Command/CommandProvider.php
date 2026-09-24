<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Command;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Registers the wp-core:* Composer commands.
 */
class CommandProvider implements CommandProviderCapability
{
    public function getCommands(): array
    {
        return [
            new DeployCommand(),
            new StatusCommand(),
            new VerifyCommand(),
        ];
    }
}
