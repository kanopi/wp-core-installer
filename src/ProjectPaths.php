<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\Util\Filesystem;

/**
 * Single source of truth for every path this plugin reads or writes.
 *
 * All returned paths are absolute, use forward slashes, have no trailing
 * slash, and contain no "." or ".." segments, so they can be compared and
 * relativised reliably.
 *
 * Project root
 * ────────────
 * Composer resolves vendor-dir and installer-paths against its working
 * directory (after --working-dir / -d has been applied), NOT against the
 * directory of a composer.json named via the COMPOSER env var. We use the
 * same base so our paths always line up with where Composer put things.
 *
 * Configuration (root package composer.json)
 * ──────────────────────────────────────────
 *   "extra": {
 *       "wordpress-install-dir": "public",              // default; "." = project root
 *       "wp-core-installer": {
 *           "mu-plugins-dir": "wp-content/mu-plugins"   // default; relative to the web-root
 *       }
 *   }
 */
class ProjectPaths
{
    /** Default web-root relative to the project root. */
    public const DEFAULT_INSTALL_DIR = 'public';

    /** Default mu-plugins directory relative to the web-root. */
    public const DEFAULT_MU_PLUGINS_DIR = 'wp-content/mu-plugins';

    private Filesystem $filesystem;

    public function __construct(private Composer $composer)
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Absolute path to the project root (Composer's working directory).
     */
    public function projectRoot(): string
    {
        $cwd = (string) getcwd();

        return $this->filesystem->normalizePath(realpath($cwd) ?: $cwd);
    }

    /**
     * Absolute path to the directory WordPress core is deployed into.
     */
    public function webRoot(): string
    {
        $raw = self::requireString(
            $this->composer->getPackage()->getExtra()['wordpress-install-dir'] ?? self::DEFAULT_INSTALL_DIR,
            'extra.wordpress-install-dir'
        );

        return $this->resolve($this->projectRoot(), $raw);
    }

    /**
     * Absolute path to the WordPress must-use plugins directory.
     */
    public function muPluginsDir(): string
    {
        $raw = $this->configString('mu-plugins-dir', self::DEFAULT_MU_PLUGINS_DIR);

        return $this->resolve($this->webRoot(), $raw);
    }

    /**
     * Absolute path to the Composer vendor directory.
     */
    public function vendorDir(): string
    {
        $raw = self::requireString($this->composer->getConfig()->get('vendor-dir'), 'config.vendor-dir');

        return $this->resolve($this->projectRoot(), $raw);
    }

    /**
     * The extra.wp-core-installer config array from the root package.
     *
     * @return array<mixed>
     */
    public function pluginConfig(): array
    {
        $config = $this->composer->getPackage()->getExtra()['wp-core-installer'] ?? [];

        if (!is_array($config)) {
            throw new \UnexpectedValueException(
                'WP Core Installer: extra.wp-core-installer in composer.json must be an object.'
            );
        }

        return $config;
    }

    /**
     * A string setting from extra.wp-core-installer, or $default when unset.
     */
    public function configString(string $key, string $default): string
    {
        return self::requireString($this->pluginConfig()[$key] ?? $default, 'extra.wp-core-installer.' . $key);
    }

    /**
     * A list-of-strings setting from extra.wp-core-installer ([] when unset).
     *
     * @return string[]
     */
    public function configStringList(string $key): array
    {
        $value = $this->pluginConfig()[$key] ?? [];
        $label = 'extra.wp-core-installer.' . $key;

        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                sprintf('WP Core Installer: %s in composer.json must be an array of strings.', $label)
            );
        }

        return array_values(array_map(
            static fn (mixed $item): string => self::requireString($item, $label . '[]'),
            $value
        ));
    }

    /**
     * Reject non-string config values with a message naming the setting,
     * instead of letting them surface later as a TypeError or an
     * "Array to string conversion" warning.
     */
    private static function requireString(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                sprintf(
                    'WP Core Installer: %s in composer.json must be a string, %s given.',
                    $label,
                    get_debug_type($value)
                )
            );
        }

        return $value;
    }

    /**
     * Resolve $path against $base unless it is already absolute, then
     * normalise it: "./public/", "public//", "public/../public" → "{base}/public".
     */
    private function resolve(string $base, string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '.') {
            return $base;
        }

        if (!$this->filesystem->isAbsolutePath($path)) {
            $path = $base . '/' . $path;
        }

        return $this->canonicalise($this->filesystem->normalizePath($path));
    }

    /**
     * Resolve symlinks in the longest existing prefix of $path, keeping any
     * not-yet-created tail as-is. Without this, a configured "/var/www" and
     * a project root of "/private/var/…" (macOS) share no common prefix and
     * relative paths computed between them are wrong.
     */
    private function canonicalise(string $path): string
    {
        $tail = [];
        $head = $path;

        while ($head !== '' && $head !== dirname($head) && !is_dir($head)) {
            array_unshift($tail, basename($head));
            $head = dirname($head);
        }

        $real = realpath($head);

        if ($real === false) {
            return $path;
        }

        return $this->filesystem->normalizePath(implode('/', array_merge([$real], $tail)));
    }
}
