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
     * Never added to .gitignore; the user decides whether to track these.
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
    private ProjectPaths $paths;

    /** Set once core has been deployed in this Composer process. */
    private bool $deployedThisRun = false;

    public function __construct(
        IOInterface $io,
        Composer $composer,
        ?string $type = 'library',
        ?Filesystem $filesystem = null,
        ?BinaryInstaller $binaryInstaller = null
    ) {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
        $this->paths            = new ProjectPaths($composer);
        $this->gitignoreManager = new GitignoreManager($io, $this->paths->pluginConfig());
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
            $this->deployToWebRoot($package, $this->getInstallPath($package));
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
            $this->deployToWebRoot($target, $this->getInstallPath($target));
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

        $this->gitignoreManager->removeCoreBlock($this->paths->projectRoot());

        return parent::uninstall($repo, $package);
    }

    // -------------------------------------------------------------------------
    // Post-event deployment (handles the vendor-cache scenario)
    // -------------------------------------------------------------------------

    /**
     * Called from the post-install/update event to guarantee core files are
     * present in the web-root even when Composer considered the package
     * already installed (vendor cache hit) and skipped install()/update(),
     * OR when this plugin was required AFTER core was already installed by
     * the default installer (the `composer require` scenario).
     *
     * Skips the copy when install()/update() already deployed in this run,
     * or when the deploy manifest shows the same build is already in place
     * and every recorded file is still on disk.
     */
    public function ensureCoreDeployed(bool $force = false): void
    {
        if ($this->deployedThisRun && !$force) {
            $this->io->write('  - Core already deployed during this run; skipping.', true, IOInterface::VERBOSE);
            return;
        }

        // Find a wordpress-core package — try the local repo first, then the lock file.
        $package = $this->findWordPressCorePackage();

        if ($package === null) {
            $this->io->write(
                '  - No wordpress-core package found; skipping deployment.',
                true,
                IOInterface::VERBOSE
            );
            return;
        }

        $source = $this->locateCoreSource($package);

        if ($source === null) {
            $this->io->write(
                sprintf(
                    '  - Core source directory not found for <comment>%s</comment>; skipping deployment.',
                    $package->getPrettyName()
                ),
                true,
                IOInterface::VERBOSE
            );
            return;
        }

        $webRoot  = $this->paths->webRoot();
        $expected = $this->manifestFor($package, $webRoot, $this->buildProtectedList(), $this->buildSkipIfExistsList());
        $previous = $this->previousManifest();

        if (!$force && $previous !== null && $previous->describesSameDeployAs($expected) && $previous->isIntact()) {
            $this->io->write(
                sprintf(
                    '<info>WP Core Installer:</info> %s is up to date in the web-root; skipping deploy.',
                    $package->getPrettyName()
                )
            );
            // Still refresh the core block so .gitignore settings take effect.
            $this->gitignoreManager->updateCoreBlock(
                $this->paths->projectRoot(),
                $webRoot,
                $previous->files,
                $this->paths->vendorDir()
            );
            return;
        }

        $this->io->write(
            sprintf('<info>WP Core Installer:</info> Ensuring %s is deployed to web-root…', $package->getPrettyName())
        );
        $this->deployToWebRoot($package, $source);
    }

    /**
     * Resolve the on-disk directory that currently holds the core package's
     * files. Normally this is our private staging directory, but when the
     * plugin was required AFTER core (e.g. `composer require kanopi/wp-core-installer`
     * into an existing project), core was placed by the default installer at
     * its conventional vendor path instead — so fall back to that.
     */
    public function locateCoreSource(PackageInterface $package): ?string
    {
        $candidates = [
            $this->getInstallPath($package),              // vendor/.wordpress-core-staging/<name>
            $this->vendorDir . '/' . $package->getName(), // vendor/<name> (default installer)
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Search the local installed repository and (as fallback) the lock file
     * for a package of type "wordpress-core".
     */
    public function findWordPressCorePackage(): ?PackageInterface
    {
        // 1. Check the local installed repository.
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($localRepo->getPackages() as $package) {
            if ($package->getType() === 'wordpress-core') {
                return $package;
            }
        }

        // 2. Fall back to the lock file — covers scenarios where the local
        //    repo hasn't been fully populated yet (e.g. plugin loaded via
        //    patch before the full install completes).
        $locker = $this->composer instanceof Composer ? $this->composer->getLocker() : null;
        if ($locker !== null && $locker->isLocked()) {
            foreach ($locker->getLockedRepository(true)->getPackages() as $package) {
                if ($package->getType() === 'wordpress-core') {
                    return $package;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Core deployment logic
    // -------------------------------------------------------------------------

    /**
     * Plan and apply a deployment of $sourceDir into the web-root.
     *
     * @param string $sourceDir Directory holding the extracted core files
     *                          (normally the staging dir, but may be the
     *                          default vendor path — see locateCoreSource()).
     */
    private function deployToWebRoot(PackageInterface $package, string $sourceDir): void
    {
        $this->applyPlan($this->planDeployment($package, $sourceDir));
    }

    /**
     * Work out what deploying $sourceDir would do, without writing anything.
     *
     * Three-tier classification per path (skip-if-exists is checked first so
     * that e.g. wp-content/themes/index.php passes through even though its
     * parent directory is protected), then a content comparison for files
     * that would be written, then stale-file detection against the previous
     * deploy manifest.
     */
    public function planDeployment(PackageInterface $package, string $sourceDir): DeployPlan
    {
        $stagingPath = realpath($sourceDir);

        if ($stagingPath === false || !is_dir($stagingPath)) {
            throw new \RuntimeException(
                sprintf(
                    'WP Core Installer: core source directory not found at "%s". '
                    . 'The package may not have been extracted correctly.',
                    $sourceDir
                )
            );
        }

        $webRoot     = $this->paths->webRoot();
        $protected   = $this->buildProtectedList();
        $skipIfExist = $this->buildSkipIfExistsList();
        $plan        = new DeployPlan(
            $stagingPath,
            $webRoot,
            $this->manifestFor($package, $webRoot, $protected, $skipIfExist)
        );

        /**
         * Every always-synced file the source ships. Stale-file detection
         * diffs against this, and it becomes the manifest's file list.
         *
         * @var string[] $shipped
         */
        $shipped = [];

        /** @var \SplFileInfo $item */
        foreach ($this->createIterator($stagingPath) as $item) {
            // getPathname(), not getRealPath(): a symlink inside the package
            // must not resolve to a path outside the staging directory.
            $relative    = $this->relativePath($stagingPath, $item->getPathname());
            $source      = $item->getPathname();
            $destination = $webRoot . '/' . $relative;

            if ($this->isSkipIfExists($relative, $skipIfExist)) {
                if ($item->isDir()) {
                    continue;
                }
                if (file_exists($destination)) {
                    $plan->keptExisting[] = $relative;
                } else {
                    $plan->firstInstall[$relative] = $source;
                }
                continue;
            }

            if ($this->isProtected($relative, $protected)) {
                $plan->protected[] = $relative;
                continue;
            }

            if ($item->isDir()) {
                $plan->directories[] = $relative;
                continue;
            }

            $shipped[] = $relative;

            if (!is_file($destination)) {
                $plan->create[$relative] = $source;
            } elseif (!$this->sameContent($source, $destination)) {
                $plan->update[$relative] = $source;
            } else {
                $plan->unchanged[] = $relative;
            }
        }

        $plan->manifest = $plan->manifest->withFiles($shipped);
        $plan->stale    = $this->staleFiles($plan->manifest, $protected, $skipIfExist);

        return $plan;
    }

    /**
     * Carry out a plan: write new and changed files, delete stale ones, then
     * save the manifest and refresh the core .gitignore block.
     */
    public function applyPlan(DeployPlan $plan): void
    {
        $this->io->write(sprintf('  - Web-root: <comment>%s</comment>', $plan->webRoot));
        $this->filesystem->ensureDirectoryExists($plan->webRoot);

        foreach ($plan->directories as $directory) {
            $this->filesystem->ensureDirectoryExists($plan->webRoot . '/' . $directory);
        }

        $failed = [];

        foreach ($plan->create + $plan->update + $plan->firstInstall as $relative => $source) {
            $destination = $plan->webRoot . '/' . $relative;
            $this->filesystem->ensureDirectoryExists(dirname($destination));

            if (!@copy($source, $destination)) {
                $failed[] = $relative;
                $this->io->writeError(sprintf('  - <error>Failed to copy:</error> %s → %s', $source, $destination));
                continue;
            }

            $this->io->write(sprintf('  - Wrote %s', $relative), true, IOInterface::VERY_VERBOSE);
        }

        $this->io->write(
            sprintf(
                '  - Done: <info>%d created</info>, <info>%d updated</info>, %d unchanged, '
                . '<comment>%d skipped</comment>.',
                count($plan->create) + count($plan->firstInstall),
                count($plan->update),
                count($plan->unchanged),
                count($plan->keptExisting) + count($plan->protected)
            )
        );

        $removed = 0;

        foreach ($plan->stale as $file) {
            $absolute = $plan->webRoot . '/' . $file;

            if (!is_file($absolute) || !@unlink($absolute)) {
                continue;
            }

            $this->io->write(sprintf('  - <comment>Removed stale:</comment> %s', $file), true, IOInterface::VERBOSE);
            $this->pruneEmptyDirectories(dirname($absolute), $plan->webRoot);
            $removed++;
        }

        if ($removed > 0) {
            $this->io->write(
                sprintf('  - Removed <comment>%d stale file(s)</comment> no longer shipped by core.', $removed)
            );
        }

        $this->deployedThisRun = true;

        // A failed copy is left out of the manifest, so the next run notices
        // the file is missing and redeploys instead of trusting it is there.
        $manifest = $failed === [] ? $plan->manifest : $plan->manifest->withFiles(
            array_values(array_diff($plan->manifest->files, $failed))
        );

        if (!$manifest->save($this->manifestPath())) {
            $this->io->writeError(
                sprintf('  - <warning>Could not write deploy manifest at %s</warning>', $this->manifestPath())
            );
        }

        $this->gitignoreManager->updateCoreBlock(
            $this->paths->projectRoot(),
            $plan->webRoot,
            $manifest->files,
            $this->paths->vendorDir()
        );
    }

    private function sameContent(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && md5_file($a) === md5_file($b);
    }

    // -------------------------------------------------------------------------
    // Helpers: deploy manifest / stale files
    // -------------------------------------------------------------------------

    private function manifestPath(): string
    {
        return $this->vendorDir . '/.wordpress-core-staging/.deploy-manifest.json';
    }

    /**
     * @param string[] $protected
     * @param string[] $skipIfExists
     */
    private function manifestFor(
        PackageInterface $package,
        string $webRoot,
        array $protected,
        array $skipIfExists
    ): DeployManifest {
        return new DeployManifest(
            $package->getName(),
            $package->getVersion(),
            (string) ($package->getDistReference() ?? $package->getSourceReference() ?? ''),
            $webRoot,
            sha1((string) json_encode([$protected, $skipIfExists]))
        );
    }

    /**
     * Files recorded by the previous deploy that the planned one no longer
     * ships and that still exist, i.e. what WordPress's own updater would
     * delete via $_old_files.
     *
     * Never includes protected or skip-if-exists paths, and is empty when
     * there is no previous manifest or the web-root has moved.
     *
     * @param string[] $protected
     * @param string[] $skipIfExists
     * @return string[]
     */
    private function staleFiles(DeployManifest $planned, array $protected, array $skipIfExists): array
    {
        $previous = $this->previousManifest();

        if ($previous === null) {
            return [];
        }

        if ($previous->webRoot !== $planned->webRoot) {
            $this->io->write(
                sprintf(
                    '  - <comment>Web-root changed since last deploy</comment> (%s); not removing stale files.',
                    $previous->webRoot
                ),
                true,
                IOInterface::VERBOSE
            );
            return [];
        }

        $stale = [];

        foreach ($previous->filesRemovedIn($planned) as $file) {
            if (
                $this->filesystem->isAbsolutePath($file)
                || in_array('..', explode('/', $file), true)
                || $this->isProtected($file, $protected)
                || $this->isSkipIfExists($file, $skipIfExists)
                || !is_file($planned->webRoot . '/' . $file)
            ) {
                continue;
            }

            $stale[] = $file;
        }

        return $stale;
    }

    /**
     * The manifest written by the last deploy, if any.
     */
    public function previousManifest(): ?DeployManifest
    {
        return DeployManifest::load($this->manifestPath());
    }

    /**
     * Whether $relative (web-root-relative) is an always-synced core path,
     * i.e. neither protected nor skip-if-exists. Used by wp-core:verify.
     */
    public function isAlwaysSynced(string $relative): bool
    {
        return !$this->isSkipIfExists($relative, $this->buildSkipIfExistsList())
            && !$this->isProtected($relative, $this->buildProtectedList());
    }

    /**
     * Remove $dir and its parents while they are empty, stopping at $webRoot.
     */
    private function pruneEmptyDirectories(string $dir, string $webRoot): void
    {
        while (
            str_starts_with($dir, $webRoot . '/')
            && is_dir($dir)
            && $this->filesystem->isDirEmpty($dir)
            && @rmdir($dir)
        ) {
            $dir = dirname($dir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers: protection lists
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function buildProtectedList(): array
    {
        $userExtra = $this->paths->configStringList('protected-paths');

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
        $userExtra = $this->paths->configStringList('skip-if-exists');

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

    /** @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator> */
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

    /**
     * $fullPath relative to $baseDir with forward slashes. Both are
     * normalised first: on Windows realpath() yields backslashes while the
     * iterator (UNIX_PATHS) appends children with forward slashes.
     */
    private function relativePath(string $baseDir, string $fullPath): string
    {
        $baseDir  = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
        $fullPath = str_replace('\\', '/', $fullPath);

        if (str_starts_with($fullPath, $baseDir)) {
            return substr($fullPath, strlen($baseDir));
        }

        return $fullPath;
    }
}
