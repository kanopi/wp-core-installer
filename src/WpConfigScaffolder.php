<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\IO\IOInterface;

/**
 * Creates a starter wp-config.php in the web-root on first install (opt-in).
 *
 * wp-config.php is always protected: it is written at most once and never
 * overwritten, updated or gitignored. The default template reads every
 * setting, including keys and salts, from the environment, so nothing
 * secret is written to disk.
 *
 * Configuration
 * ─────────────
 *   "extra": {
 *       "wp-core-installer": {
 *           "scaffold-wp-config": true,                          // default false
 *           "wp-config-template": "config/wp-config.template.php" // optional
 *       }
 *   }
 *
 * Template placeholders (both relative to the web-root, for use with __DIR__):
 *   {{AUTOLOAD_RELATIVE_PATH}}      e.g. "../vendor/autoload.php"
 *   {{PROJECT_ROOT_RELATIVE_PATH}}  e.g. "../" ("" when the web-root is the project root)
 */
class WpConfigScaffolder
{
    private ProjectPaths $paths;

    public function __construct(
        private Composer $composer,
        private IOInterface $io
    ) {
        $this->paths = new ProjectPaths($composer);
    }

    /**
     * Returns true when a wp-config.php was created.
     */
    public function scaffold(): bool
    {
        if (!$this->paths->configBool('scaffold-wp-config', false) || !$this->hasCorePackage()) {
            return false;
        }

        $webRoot = $this->paths->webRoot();
        $target  = $webRoot . '/wp-config.php';

        if (is_file($target)) {
            $this->io->write('  - wp-config.php already exists; leaving it untouched.', true, IOInterface::VERBOSE);
            return false;
        }

        // WordPress also loads wp-config.php from one directory above ABSPATH
        // (when that directory is not itself a WordPress install). Creating a
        // second one in the web-root would silently shadow it.
        $parent = dirname($webRoot);
        if (is_file($parent . '/wp-config.php') && !is_file($parent . '/wp-settings.php')) {
            $this->io->write(sprintf(
                '  - <comment>Not creating wp-config.php:</comment> WordPress already loads %s/wp-config.php.',
                $this->display($parent)
            ));
            return false;
        }

        [$template, $templatePath] = $this->loadTemplate();

        $projectRootRelative = ProjectPaths::relativePath($webRoot, $this->paths->projectRoot());
        $contents            = strtr($template, [
            '{{AUTOLOAD_RELATIVE_PATH}}'     => ProjectPaths::relativePath(
                $webRoot,
                $this->paths->vendorDir() . '/autoload.php'
            ),
            '{{PROJECT_ROOT_RELATIVE_PATH}}' => $projectRootRelative === '' ? '' : $projectRootRelative . '/',
        ]);

        if (!is_dir($webRoot) || file_put_contents($target, $contents) === false) {
            $this->io->writeError(sprintf('  - <error>WP Core Installer: could not write %s</error>', $target));
            return false;
        }

        $this->io->write(sprintf(
            '<info>WP Core Installer:</info> Created %s from %s.',
            $this->display($target),
            $this->display($templatePath)
        ));

        if ($templatePath === $this->defaultTemplatePath()) {
            $this->io->write(
                '  - It reads DB_NAME, DB_USER, DB_PASSWORD, DB_HOST, keys and salts from the environment.'
            );
        }

        return true;
    }

    /**
     * @return array{string, string} Template contents and its path.
     */
    private function loadTemplate(): array
    {
        $configured = $this->paths->configString('wp-config-template', '');
        $path       = $configured === ''
            ? $this->defaultTemplatePath()
            : ($this->isAbsolute($configured) ? $configured : $this->paths->projectRoot() . '/' . $configured);

        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException(sprintf(
                'WP Core Installer: wp-config template not found at "%s" (extra.wp-core-installer.wp-config-template).',
                $path
            ));
        }

        return [$contents, $path];
    }

    private function defaultTemplatePath(): string
    {
        return dirname(__DIR__) . '/resources/wp-config.php';
    }

    private function hasCorePackage(): bool
    {
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if ($package->getType() === 'wordpress-core') {
                return true;
            }
        }

        return false;
    }

    private function isAbsolute(string $path): bool
    {
        return (new \Composer\Util\Filesystem())->isAbsolutePath($path);
    }

    private function display(string $path): string
    {
        $relative = ProjectPaths::relativePath($this->paths->projectRoot(), str_replace('\\', '/', $path));

        return str_starts_with($relative, '..') ? $path : $relative;
    }
}
