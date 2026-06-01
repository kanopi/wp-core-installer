<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;

/**
 * Shared reader for the `extra.wp-core-installer.manage-gitignore` flag.
 *
 * Default is true (the plugin manages its .gitignore blocks). Set to false in
 * the root package's composer.json for projects that control which files are
 * committed via CI/CD rather than via .gitignore — e.g. Pantheon's
 * `terminus build:env:push` build-artifact model, where a gitignored core or
 * plugin would be missing from the pushed artifact.
 */
trait GitignoreOption
{
    /**
     * Whether this plugin should manage the project's .gitignore blocks.
     *
     * Reads from the ROOT package's extra. Uses FILTER_VALIDATE_BOOLEAN so a
     * mistakenly quoted "false" in composer.json is still honoured; any
     * unrecognised value falls back to the safe default (managed).
     */
    private function isGitignoreManaged(Composer $composer): bool
    {
        $extra = $composer->getPackage()->getExtra();
        $value = $extra['wp-core-installer']['manage-gitignore'] ?? true;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
