<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

/**
 * What a core deployment would do, computed without touching the web-root.
 *
 * CoreInstaller builds a plan, then either applies it (a real deploy) or
 * reports it (`composer wp-core:deploy --dry-run`, `composer wp-core:status`).
 *
 * All paths are web-root-relative with forward slashes. Files are compared by
 * content, so an unchanged file is neither rewritten nor has its mtime reset.
 */
class DeployPlan
{
    /**
     * @param string                $sourceDir     Absolute path of the extracted core package.
     * @param string                $webRoot       Absolute web-root path.
     * @param DeployManifest        $manifest      Manifest to save after applying (lists every
     *                                             always-synced file that will be on disk).
     * @param array<string, string> $create        Always-synced files missing from the web-root.
     * @param array<string, string> $update        Always-synced files whose content differs.
     * @param string[]              $unchanged     Always-synced files already identical.
     * @param array<string, string> $firstInstall  Skip-if-exists files not yet present.
     * @param string[]              $keptExisting  Skip-if-exists files already present (left alone).
     * @param string[]              $protected     Paths skipped because they are protected.
     * @param string[]              $stale         Previously deployed files this source no longer ships.
     * @param string[]              $directories   Always-synced directories to ensure exist.
     *
     * The array<string, string> maps are relative path => absolute source path.
     */
    public function __construct(
        public string $sourceDir,
        public string $webRoot,
        public DeployManifest $manifest,
        public array $create = [],
        public array $update = [],
        public array $unchanged = [],
        public array $firstInstall = [],
        public array $keptExisting = [],
        public array $protected = [],
        public array $stale = [],
        public array $directories = []
    ) {
    }

    /**
     * Whether applying the plan would change anything on disk.
     */
    public function hasChanges(): bool
    {
        return $this->create !== [] || $this->update !== [] || $this->firstInstall !== [] || $this->stale !== [];
    }

    /**
     * One-line human summary, e.g. "3 to create, 1 to update, 2 stale to delete".
     */
    public function summary(): string
    {
        $parts = array_filter([
            $this->create !== [] ? count($this->create) . ' to create' : null,
            $this->update !== [] ? count($this->update) . ' to update' : null,
            $this->firstInstall !== [] ? count($this->firstInstall) . ' first-install' : null,
            $this->stale !== [] ? count($this->stale) . ' stale to delete' : null,
        ]);

        return $parts === [] ? 'no changes' : implode(', ', $parts);
    }
}
