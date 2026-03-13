<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Scans the installed package repository after each Composer run and
 * rebuilds the "packages" managed block in .gitignore so that every
 * Composer-managed WordPress plugin, theme, and must-use plugin is
 * correctly excluded from version control.
 *
 * This handler is intentionally decoupled from CoreInstaller — it fires
 * on POST_INSTALL_CMD / POST_UPDATE_CMD so it sees the full, resolved
 * dependency graph rather than individual package install events.
 *
 * Package types handled
 * ─────────────────────
 *   wordpress-plugin         → wp-content/plugins/{name}/
 *   wordpress-theme          → wp-content/themes/{name}/
 *   wordpress-theme-custom   → wp-content/themes/custom/{name}/ (or wherever installer-paths maps it)
 *   wordpress-muplugin       → wp-content/mu-plugins/{name}/
 *
 * Any package whose install path falls OUTSIDE the project root is silently
 * skipped (this plugin only manages paths it can express as repo-relative).
 */
class PackageGitignoreHandler
{
    /**
     * Package types that represent installable WordPress content.
     * The key is the Composer package type; the value is the section label
     * used in .gitignore comments and in the $byType map.
     */
    private const WORDPRESS_TYPES = [
        'wordpress-plugin'       => 'plugins',
        'wordpress-theme'        => 'themes',
        'wordpress-theme-custom' => 'themes',
        'wordpress-muplugin'     => 'mu-plugins',
    ];

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        private readonly GitignoreManager $gitignoreManager
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Resolve all relevant installed package paths and refresh the
     * "packages" .gitignore block.
     */
    public function handle(): void
    {
        $projectRoot  = (string) getcwd();
        $vendorDirAbs = (string) $this->composer->getConfig()->get('vendor-dir');

        // Ask the scaffolder for the managed file path (null when opt-out).
        // The file does not need to exist yet — we gitignore it by path so
        // it is excluded from the first `git add .` even before the first
        // `composer install` has run in a fresh checkout.
        $muPluginFile = (new MuPluginScaffolder($this->composer, $this->io))->resolveOutputPath();

        $byType = $this->resolvePackagePaths($projectRoot);

        $this->gitignoreManager->updatePackagesBlock($projectRoot, $vendorDirAbs, $byType, $muPluginFile);
    }

    // -------------------------------------------------------------------------
    // Package path resolution
    // -------------------------------------------------------------------------

    /**
     * Iterate every locally installed package, filter to WordPress content
     * types, and return their install paths grouped by section label.
     *
     * @return array<string, string[]>  e.g. ['plugins' => ['wp-content/plugins/akismet'], ...]
     */
    private function resolvePackagePaths(string $projectRoot): array
    {
        $installManager = $this->composer->getInstallationManager();
        $localRepo      = $this->composer->getRepositoryManager()->getLocalRepository();

        /** @var array<string, string[]> $byType */
        $byType = [];

        foreach ($localRepo->getPackages() as $package) {
            $section = $this->wpSection($package);

            if ($section === null) {
                continue;
            }

            $installPathAbs = $installManager->getInstallPath($package);

            if ($installPathAbs === null || $installPathAbs === '') {
                $this->io->write(
                    sprintf(
                        '  - <comment>Skipping %s</comment>: could not resolve install path.',
                        $package->getPrettyName()
                    ),
                    true,
                    IOInterface::VERBOSE
                );
                continue;
            }

            // Resolve symlinks / relative segments so the comparison is reliable.
            $resolved = realpath($installPathAbs) ?: $installPathAbs;
            $relative = $this->gitignoreManager->relativeToProject($projectRoot, $resolved);

            if ($relative === $resolved) {
                // relativeToProject returns the input unchanged when the path
                // is outside the project root — skip those.
                $this->io->write(
                    sprintf(
                        '  - <comment>Skipping %s</comment>: install path is outside project root.',
                        $package->getPrettyName()
                    ),
                    true,
                    IOInterface::VERBOSE
                );
                continue;
            }

            $byType[$section][] = $relative;

            $this->io->write(
                sprintf(
                    '  - <comment>[%s]</comment> %s → %s',
                    $section,
                    $package->getPrettyName(),
                    $relative
                ),
                true,
                IOInterface::VERBOSE
            );
        }

        return $byType;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the .gitignore section label for a package, or null if the
     * package type is not a WordPress content type we manage.
     */
    private function wpSection(PackageInterface $package): ?string
    {
        return self::WORDPRESS_TYPES[$package->getType()] ?? null;
    }
}