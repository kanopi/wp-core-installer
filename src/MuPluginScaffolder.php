<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

/**
 * Writes the Composer autoloader bootstrap file into the WordPress
 * must-use plugins directory on every `composer install` / `composer update`.
 *
 * WordPress does NOT load files from subdirectories of wp-content/mu-plugins/
 * automatically.  A top-level PHP file in that directory is required to
 * bootstrap Composer so that all installed packages are available.
 *
 * Like vendor/ itself, this file is fully managed by Composer.  It is always
 * regenerated (never skip-if-exists) and is gitignored by the packages block.
 * If you need to customise the bootstrap logic, set the opt-out flag and manage
 * the file yourself:
 *
 *   "extra": {
 *       "wp-core-installer": {
 *           "manage-mu-plugin-autoloader": false
 *       }
 *   }
 *
 * Path resolution
 * ───────────────
 * The mu-plugin uses __DIR__ to locate vendor/autoload.php, so the relative
 * path baked into the file is computed from the actual positions of the
 * mu-plugins directory and the vendor directory at generation time:
 *
 *   mu-plugins : {project}/wp-content/mu-plugins
 *   vendor     : {project}/wp-content/mu-plugins/vendor  →  vendor/autoload.php
 *
 *   mu-plugins : {project}/wp-content/mu-plugins
 *   vendor     : {project}/vendor                        →  ../../vendor/autoload.php
 *
 * Configuration
 * ─────────────
 *   "extra": {
 *       "wp-core-installer": {
 *           "mu-plugins-dir":             "wp-content/mu-plugins",  // default
 *           "mu-plugin-autoloader-file":  "autoloader.php",         // default
 *           "manage-mu-plugin-autoloader": true                      // default
 *       }
 *   }
 */
class MuPluginScaffolder
{
    /** Placeholder replaced with the computed relative path when scaffolding. */
    private const PATH_PLACEHOLDER = '{{AUTOLOAD_RELATIVE_PATH}}';

    /** Default output filename inside the mu-plugins directory. */
    private const DEFAULT_FILENAME = '000-autoloader.php';

    /** Default mu-plugins directory relative to the project root. */
    private const DEFAULT_MU_PLUGINS_DIR = 'wp-content/mu-plugins';

    private Filesystem $filesystem;

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io
    ) {
        $this->filesystem = new Filesystem();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Write (or overwrite) the autoloader mu-plugin.
     *
     * Returns true when the file was written, false when management is
     * disabled via extra.wp-core-installer.manage-mu-plugin-autoloader = false.
     */
    public function scaffold(): bool
    {
        // ── Opt-out check ─────────────────────────────────────────────────────
        if (!$this->isManaged()) {
            $this->io->write(
                '  - <comment>mu-plugin autoloader management disabled</comment> (manage-mu-plugin-autoloader = false).',
                true,
                IOInterface::VERBOSE
            );
            return false;
        }

        $projectRoot  = (string) getcwd();
        $muPluginsDir = $this->resolveMuPluginsDir($projectRoot);
        $outputFile   = $muPluginsDir . DIRECTORY_SEPARATOR . $this->resolveFilename();

        // ── Compute the relative path from mu-plugins dir to autoload.php ─────
        $vendorDir    = (string) $this->composer->getConfig()->get('vendor-dir');
        $autoloadAbs  = $vendorDir . DIRECTORY_SEPARATOR . 'autoload.php';
        $relativePath = $this->computeRelativePath($muPluginsDir, $autoloadAbs);

        // ── Load the stub template and inject the resolved path ───────────────
        $stub = $this->loadStub();
        $stub = str_replace(self::PATH_PLACEHOLDER, $relativePath, $stub);

        // ── Write (always overwrite — this file is Composer-managed) ─────────
        $this->filesystem->ensureDirectoryExists($muPluginsDir);

        if (file_put_contents($outputFile, $stub) === false) {
            $this->io->writeError(
                sprintf(
                    '  - <e>WP Core Installer: failed to write mu-plugin at %s</e>',
                    $outputFile
                )
            );
            return false;
        }

        $this->io->write(
            sprintf(
                '  - <info>Written mu-plugin autoloader</info>: %s',
                $this->relativise($projectRoot, $outputFile)
            )
        );

        return true;
    }

    /**
     * Returns the absolute path to the managed mu-plugin file, regardless of
     * whether it currently exists on disk.  Used by PackageGitignoreHandler
     * to add the file to .gitignore.
     *
     * Returns null when management is disabled.
     */
    public function resolveOutputPath(): ?string
    {
        if (!$this->isManaged()) {
            return null;
        }

        $projectRoot  = (string) getcwd();
        $muPluginsDir = $this->resolveMuPluginsDir($projectRoot);

        return $muPluginsDir . DIRECTORY_SEPARATOR . $this->resolveFilename();
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    /**
     * Returns false when the project has opted out of autoloader management.
     */
    private function isManaged(): bool
    {
        $extra = $this->composer->getPackage()->getExtra();

        // Default true — opt out by setting the flag to false.
        return (bool) ($extra['wp-core-installer']['manage-mu-plugin-autoloader'] ?? true);
    }

    /**
     * Resolve the absolute path to the mu-plugins directory.
     *
     * Reads extra.wp-core-installer.mu-plugins-dir (relative to project root),
     * falling back to wp-content/mu-plugins.
     */
    private function resolveMuPluginsDir(string $projectRoot): string
    {
        $extra  = $this->composer->getPackage()->getExtra();
        $rawDir = $extra['wp-core-installer']['mu-plugins-dir'] ?? self::DEFAULT_MU_PLUGINS_DIR;

        if (str_starts_with($rawDir, '/')) {
            return $rawDir;
        }

        return $projectRoot . DIRECTORY_SEPARATOR . $rawDir;
    }

    /**
     * Resolve the output filename for the mu-plugin.
     */
    private function resolveFilename(): string
    {
        $extra = $this->composer->getPackage()->getExtra();

        return (string) ($extra['wp-core-installer']['mu-plugin-autoloader-file'] ?? self::DEFAULT_FILENAME);
    }

    /**
     * Compute the relative path from $fromDir to $toFile so the mu-plugin
     * can reference vendor/autoload.php via __DIR__ regardless of where both
     * directories sit in the filesystem.
     *
     * Example:
     *   from : /var/www/wp-content/mu-plugins
     *   to   : /var/www/wp-content/mu-plugins/vendor/autoload.php
     *   →      vendor/autoload.php
     *
     *   from : /var/www/wp-content/mu-plugins
     *   to   : /var/www/vendor/autoload.php
     *   →      ../../vendor/autoload.php
     */
    private function computeRelativePath(string $fromDir, string $toFile): string
    {
        $from = explode('/', rtrim(str_replace('\\', '/', $fromDir), '/'));
        $to   = explode('/', str_replace('\\', '/', $toFile));

        // Strip the common prefix.
        while (count($from) > 0 && count($to) > 0 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        // Each remaining segment in $from needs a "../" to climb out.
        $relative = str_repeat('../', count($from)) . implode('/', $to);

        return $relative !== '' ? $relative : 'autoload.php';
    }

    /**
     * Load the stub template from the resources directory.
     */
    private function loadStub(): string
    {
        $stubPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'autoloader.php';

        if (!is_file($stubPath)) {
            throw new \RuntimeException(
                sprintf(
                    'WP Core Installer: mu-plugin stub template not found at "%s". '
                    . 'The kanopi/wp-core-installer package may be corrupted.',
                    $stubPath
                )
            );
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new \RuntimeException(
                sprintf('WP Core Installer: could not read stub template at "%s".', $stubPath)
            );
        }

        return $contents;
    }

    /**
     * Return $absPath as a project-relative string for display purposes.
     */
    private function relativise(string $projectRoot, string $absPath): string
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
        $absPath     = str_replace('\\', '/', $absPath);

        return str_starts_with($absPath, $projectRoot)
            ? substr($absPath, strlen($projectRoot))
            : $absPath;
    }
}