<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

/**
 * Installs packages of type "wordpress-core" by:
 *
 *   1. Extracting the package into a private staging directory under vendor.
 *   2. Selectively copying files from the staging directory into the
 *      configured web-root, honouring three tiers of protection rules.
 *   3. Updating the "core" managed block in the project's .gitignore so
 *      that deployed core files are never accidentally committed.
 *
 * Three-tier protection model
 * ───────────────────────────
 *
 *   ALWAYS_PROTECTED   Never touched, not copied, not gitignored.
 *                      e.g. composer.json, wp-config.php, wp-content/themes
 *
 *   SKIP_IF_EXISTS     Copied on first install only; never overwritten;
 *                      not gitignored (user may want to track these).
 *                      e.g. .htaccess, wp-config-sample.php,
 *                           wp-content/index.php (silence-is-golden stubs)
 *
 *   Everything else    Always synced from core; gitignored automatically.
 *                      e.g. wp-admin/, wp-includes/, wp-login.php
 *
 * Configure in the ROOT package's composer.json:
 *
 *   "extra": {
 *       "wordpress-install-dir": ".",
 *       "wp-core-installer": {
 *           "protected-paths": ["my-loader.php", "config"],
 *           "skip-if-exists":  ["robots.txt"]
 *       }
 *   }
 */
class CoreInstaller extends LibraryInstaller
{
    /**
     * Paths (relative to the web-root) this installer will NEVER copy to or
     * overwrite. Directory names cause the entire subtree to be skipped.
     */
    private const ALWAYS_PROTECTED = [
        // ── Composer project files ───────────────────────────────────────────
        'composer.json',
        'composer.lock',
        // ── WordPress runtime / user config ──────────────────────────────────
        'wp-config.php',
        // ── wp-content: user-owned subdirectories ────────────────────────────
        // wp-content itself is NOT listed here so that the silence-is-golden
        // index.php stubs can land on first install. Only subdirs that belong
        // entirely to the project are hard-protected.
        'wp-content/themes',
        'wp-content/plugins',
        'wp-content/mu-plugins',
        'wp-content/uploads',
        'wp-content/upgrade',
        'wp-content/languages',
        // ── Environment / secrets ────────────────────────────────────────────
        '.env',
        '.env.local',
        '.env.staging',
        '.env.production',
        // ── VCS / editor artefacts ───────────────────────────────────────────
        '.git',
        '.gitignore',
        '.gitattributes',
        '.editorconfig',
        // ── Dependency trees managed by other tools ───────────────────────────
        'node_modules',
        'vendor',
    ];

    /**
     * Paths copied on FIRST install only (destination must not yet exist).
     * Never overwritten on `composer update` — user edits are preserved.
     * Not added to .gitignore; the user decides whether to track these.
     */
    private const SKIP_IF_EXISTS = [
        '.htaccess',
        'wp-config-sample.php',
        // Silence-is-golden directory-listing guards shipped inside wp-content.
        // Installed on first run only; never overwritten so user changes survive.
        // Note: paths inside ALWAYS_PROTECTED dirs (themes, plugins, mu-plugins)
        // still reach this tier — see tier-ordering note in deployToWebRoot().
        'wp-content/index.php',
        'wp-content/themes/index.php',
        'wp-content/plugins/index.php',
        'wp-content/mu-plugins/index.php',
    ];

    private GitignoreManager $gitignoreManager;

    public function __construct(
        IOInterface $io,
        Composer $composer,
        ?string $type = 'library',
        ?Filesystem $filesystem = null,
        ?BinaryInstaller $binaryInstaller = null
    ) {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
        $this->gitignoreManager = new GitignoreManager($io);
    }

    // -------------------------------------------------------------------------
    // PackageInterface support
    // -------------------------------------------------------------------------

    public function supports(string $packageType): bool
    {
        return $packageType === 'wordpress-core';
    }

    /**
     * Composer extracts and tracks the package in a private staging directory
     * inside vendor, so its extractor never writes directly into the web-root.
     */
    public function getInstallPath(PackageInterface $package): string
    {
        return $this->vendorDir . '/.wordpress-core-staging/' . $package->getName();
    }

    // -------------------------------------------------------------------------
    // Install / Update / Uninstall lifecycle
    // -------------------------------------------------------------------------

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): ?PromiseInterface
    {
        $promise = parent::install($repo, $package);

        $deploy = function () use ($package): void {
            $this->io->write(
                sprintf('<info>WP Core Installer:</info> Deploying %s to web-root…', $package->getPrettyName())
            );
            $this->deployToWebRoot($package);
        };

        if ($promise instanceof PromiseInterface) {
            return $promise->then($deploy);
        }

        $deploy();

        return null;
    }

    public function update(
        InstalledRepositoryInterface $repo,
        PackageInterface $initial,
        PackageInterface $target
    ): ?PromiseInterface {
        $promise = parent::update($repo, $initial, $target);

        $deploy = function () use ($target): void {
            $this->io->write(
                sprintf('<info>WP Core Installer:</info> Re-deploying %s to web-root…', $target->getPrettyName())
            );
            $this->deployToWebRoot($target);
        };

        if ($promise instanceof PromiseInterface) {
            return $promise->then($deploy);
        }

        $deploy();

        return null;
    }

    /**
     * On uninstall we deliberately do NOT wipe the web-root (a live site may
     * be running there, and wp-config.php / wp-content must survive).
     * We only remove the private staging directory and clean up .gitignore.
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package): ?PromiseInterface
    {
        $this->io->write(
            '<info>WP Core Installer:</info> Removing staging directory (web-root files are preserved).'
        );

        $this->gitignoreManager->removeCoreBlock((string) getcwd());

        return parent::uninstall($repo, $package);
    }

    // -------------------------------------------------------------------------
    // Core deployment logic
    // -------------------------------------------------------------------------

    /**
     * Copy WordPress core files from the staging directory to the web-root,
     * honouring all three protection tiers, then refresh the "core" .gitignore block.
     */
    private function deployToWebRoot(PackageInterface $package): void
    {
        $stagingPath = realpath($this->getInstallPath($package));

        if ($stagingPath === false || !is_dir($stagingPath)) {
            throw new \RuntimeException(
                sprintf(
                    'WP Core Installer: staging directory not found at "%s". '
                    . 'The package may not have been extracted correctly.',
                    $this->getInstallPath($package)
                )
            );
        }

        $projectRoot = (string) getcwd();
        $webRoot     = $this->resolveWebRoot($projectRoot);
        $this->filesystem->ensureDirectoryExists($webRoot);

        $this->io->write(sprintf('  - Web-root: <comment>%s</comment>', $webRoot));

        $protected   = $this->buildProtectedList();
        $skipIfExist = $this->buildSkipIfExistsList();

        $copied   = 0;
        $skipped  = 0;
        /** @var string[] $deployed  Normalised relative paths of every file written. */
        $deployed = [];

        /** @var \SplFileInfo $item */
        foreach ($this->createIterator($stagingPath) as $item) {
            $relativePath = $this->relativePath($stagingPath, $item->getRealPath());
            $normalised   = str_replace('\\', '/', $relativePath);
            $destination  = $webRoot . DIRECTORY_SEPARATOR . $relativePath;

            // ── Tier 1: skip-if-exists ───────────────────────────────────────
            // Checked BEFORE always-protected so that specific files listed in
            // SKIP_IF_EXISTS (e.g. wp-content/themes/index.php) can pass through
            // even when their parent directory is in ALWAYS_PROTECTED.
            if ($this->isSkipIfExists($normalised, $skipIfExist)) {
                if (file_exists($destination)) {
                    $this->io->write(
                        sprintf('  - <comment>Skipping (exists):</comment> %s', $normalised),
                        true,
                        IOInterface::VERBOSE
                    );
                    $skipped++;
                    // Still record it — the file is on disk and managed by us.
                    if (!$item->isDir()) {
                        $deployed[] = $normalised;
                    }
                    continue;
                }
                // Falls through to tier 3 (deploy) below.
            } elseif ($this->isProtected($normalised, $protected)) {
                // ── Tier 2: always-protected ─────────────────────────────────
                $this->io->write(
                    sprintf('  - <comment>Skipping protected:</comment> %s', $normalised),
                    true,
                    IOInterface::VERBOSE
                );
                $skipped++;
                continue;
            }

            // ── Tier 3: deploy ───────────────────────────────────────────────
            if ($item->isDir()) {
                $this->filesystem->ensureDirectoryExists($destination);
                continue;
            }

            $this->filesystem->ensureDirectoryExists(dirname($destination));

            if (copy($item->getRealPath(), $destination) === false) {
                $this->io->writeError(
                    sprintf('  - <e>Failed to copy:</e> %s → %s', $item->getRealPath(), $destination)
                );
            } else {
                $deployed[] = $normalised;
                $copied++;
            }
        }

        $this->io->write(
            sprintf(
                '  - Done: <info>%d file(s) copied</info>, <comment>%d path(s) skipped</comment>.',
                $copied,
                $skipped
            )
        );

        // ── Refresh .gitignore core block ─────────────────────────────────────
        $this->gitignoreManager->updateCoreBlock(
            $projectRoot,
            $webRoot,
            $deployed,
            $this->vendorDir
        );
    }

    // -------------------------------------------------------------------------
    // Helpers: path resolution
    // -------------------------------------------------------------------------

    private function resolveWebRoot(string $projectRoot): string
    {
        $extra  = $this->composer->getPackage()->getExtra();
        $rawDir = $extra['wordpress-install-dir'] ?? 'public';

        if ($rawDir === '.') {
            return $projectRoot;
        }

        if (str_starts_with($rawDir, '/')) {
            return $rawDir;
        }

        return $projectRoot . DIRECTORY_SEPARATOR . $rawDir;
    }

    // -------------------------------------------------------------------------
    // Helpers: protection lists
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function buildProtectedList(): array
    {
        $extra     = $this->composer->getPackage()->getExtra();
        $userExtra = (array) ($extra['wp-core-installer']['protected-paths'] ?? []);

        return array_values(
            array_unique(
                array_map(
                    static fn (string $p): string => trim(str_replace('\\', '/', $p), '/'),
                    array_merge(self::ALWAYS_PROTECTED, $userExtra)
                )
            )
        );
    }

    /** @return string[] */
    private function buildSkipIfExistsList(): array
    {
        $extra     = $this->composer->getPackage()->getExtra();
        $userExtra = (array) ($extra['wp-core-installer']['skip-if-exists'] ?? []);

        return array_values(
            array_unique(
                array_map(
                    static fn (string $p): string => trim(str_replace('\\', '/', $p), '/'),
                    array_merge(self::SKIP_IF_EXISTS, $userExtra)
                )
            )
        );
    }

    /** @param string[] $protected */
    private function isProtected(string $normalised, array $protected): bool
    {
        foreach ($protected as $guard) {
            if ($normalised === $guard || str_starts_with($normalised, $guard . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @param string[] $skipIfExists */
    private function isSkipIfExists(string $normalised, array $skipIfExists): bool
    {
        return in_array($normalised, $skipIfExists, true);
    }

    // -------------------------------------------------------------------------
    // Helpers: filesystem iteration
    // -------------------------------------------------------------------------

    private function createIterator(string $baseDir): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $baseDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );
    }

    private function relativePath(string $baseDir, string $fullPath): string
    {
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        if (str_starts_with($fullPath, $baseDir)) {
            return substr($fullPath, strlen($baseDir));
        }

        return $fullPath;
    }
}