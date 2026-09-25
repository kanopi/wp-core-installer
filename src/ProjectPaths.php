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

    /** Supported copy-to modes. */
    public const COPY_MODES = ['overwrite', 'if-missing', 'append', 'prepend'];

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
     * Web-root directories that hold more than one package's files. A
     * package must never be *installed* into one of these (Composer deletes
     * its whole install folder on update/remove); use copy-to instead.
     *
     * @return string[] Absolute paths.
     */
    public function sharedDirs(): array
    {
        $webRoot = $this->webRoot();

        return array_values(array_unique([
            $this->resolve($webRoot, 'wp-content'),
            $this->resolve($webRoot, 'wp-content/plugins'),
            $this->resolve($webRoot, 'wp-content/themes'),
            $this->resolve($webRoot, 'wp-content/mu-plugins'),
            $this->muPluginsDir(),
        ]));
    }

    /**
     * The effective copy-to entries: the project's own
     * (extra.wp-core-installer.copy-to) plus those declared by allowed
     * packages in *their* extra.wp-core-installer.copy-to.
     *
     * Project entries:
     *   "vendor/package:path/in/pkg" => "dest/dir/"      into dest/dir/, keeping its name
     *   "vendor/package:path/in/pkg" => "dest/new-name"  to exactly dest/new-name (rename)
     *   "vendor/package"             => "dest/dir"       every top-level entry into dest/dir/
     *   "vendor/package:path"        => {"to": ..., "mode": ..., "gitignore": ..., "comment": ...}
     *   "vendor/package:path"        => false            switch off an entry a package declares
     *
     * Package entries (only for packages listed in
     * extra.wp-core-installer.copy-to-allowed-packages): keys are paths inside
     * the package, and destinations must start with a placeholder, since a
     * package can't know the project layout:
     *   "kinsta-mu-plugins.php" => "[mu-plugins]/"
     *
     * Placeholders (usable in project entries too): [project-root], [web-root],
     * [wp-content], [mu-plugins], [plugins], [themes]. Other destinations are
     * relative to the project root (like installer-paths) or absolute. A
     * trailing "/" (or a bare placeholder) marks a destination directory.
     *
     * Modes: overwrite (default; keep the destination identical to the
     * package), if-missing (copy once, then leave it alone), append / prepend
     * (a marked section at the end / start of the destination file; files
     * only). gitignore defaults to true for overwrite and false otherwise.
     *
     * @return array<string, array{package: string, path: string, dir: string, name: string,
     *                              mode: string, gitignore: bool, comment: string, source: string}>
     *         Keyed by "vendor/package" or "vendor/package:path". "dir" is the
     *         absolute folder the copy lands in; "name" is the copied entry's
     *         name inside it ('' for a whole package); "source" says where the
     *         entry was declared.
     */
    public function copyTargets(): array
    {
        $label   = 'extra.wp-core-installer.copy-to';
        $setting = $this->pluginConfig()['copy-to'] ?? [];

        if (!is_array($setting)) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: %s in composer.json must be an object like'
                . ' {"vendor/package:file.php": "[mu-plugins]/"}.',
                $label
            ));
        }

        $entries  = [];
        $disabled = [];

        foreach ($setting as $key => $destination) {
            [$package, $path] = $this->parseCopyKey((string) $key, $label);
            $normalised       = $path === '' ? $package : $package . ':' . $path;

            if ($destination === false) {
                $disabled[$normalised] = true;
                continue;
            }

            $entries[$normalised] = $this->copyEntry($package, $path, $destination, $label . '.' . $key, false)
                + ['source' => 'composer.json'];
        }

        foreach ($this->copyAllowedPackages() as $package) {
            $extra    = $package->getExtra()['wp-core-installer'] ?? [];
            $declared = is_array($extra) ? ($extra['copy-to'] ?? []) : [];
            $name     = strtolower($package->getName());
            $pkgLabel = sprintf('%s (extra.wp-core-installer.copy-to)', $package->getPrettyName());

            if (!is_array($declared)) {
                throw new \UnexpectedValueException(
                    sprintf(
                        'WP Core Installer: %s must be an object of "path": "[placeholder]/..." entries.',
                        $pkgLabel
                    )
                );
            }

            foreach ($declared as $path => $destination) {
                [, $path]   = $this->parseCopyKey($name . ':' . (string) $path, $pkgLabel);
                $normalised = $name . ':' . $path;

                if ($path === '' || isset($entries[$normalised]) || isset($disabled[$normalised])) {
                    continue; // the project's own entry (or false) wins
                }

                $entryLabel           = $pkgLabel . ' "' . $path . '"';
                $entries[$normalised] = $this->copyEntry($name, $path, $destination, $entryLabel, true)
                    + ['source' => $package->getPrettyName()];
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Installed packages allowed to declare their own copy-to entries.
     *
     * @return \Composer\Package\PackageInterface[]
     */
    private function copyAllowedPackages(): array
    {
        $allowed = array_map('strtolower', $this->configStringList('copy-to-allowed-packages'));

        if ($allowed === []) {
            return [];
        }

        $packages = [];
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if (in_array(strtolower($package->getName()), $allowed, true)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * Split and validate a "vendor/package[:path]" key.
     *
     * @return array{string, string} Lower-cased package name, normalised path ('' = whole package).
     */
    private function parseCopyKey(string $key, string $label): array
    {
        if (preg_match('{^([a-z0-9_.-]+/[a-z0-9_.-]+)(?::(.+))?$}i', $key, $match) !== 1) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: %s keys must look like "vendor/package" or "vendor/package:path/in/package",'
                . ' "%s" given.',
                $label,
                $key
            ));
        }

        $rawPath = str_replace('\\', '/', $match[2] ?? '');
        $path    = trim($rawPath, '/');

        if (
            ($rawPath !== '' && ($this->filesystem->isAbsolutePath($rawPath) || $path === ''))
            || in_array('..', explode('/', $path), true)
        ) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: the path in %s "%s" must be relative to the package, without "..".',
                $label,
                $key
            ));
        }

        return [strtolower($match[1]), $path];
    }

    /**
     * Validate one entry's destination and options.
     *
     * @param mixed $destination     A destination string or an options object.
     * @param bool  $fromPackage     Declared by a package: destinations must use a
     *                               placeholder and stay inside the project.
     * @return array{package: string, path: string, dir: string, name: string,
     *               mode: string, gitignore: bool, comment: string}
     */
    private function copyEntry(
        string $package,
        string $path,
        mixed $destination,
        string $label,
        bool $fromPackage
    ): array {
        $options = is_array($destination) ? $destination : ['to' => $destination];

        foreach (array_keys($options) as $option) {
            if (!in_array($option, ['to', 'mode', 'gitignore', 'comment'], true)) {
                throw new \UnexpectedValueException(sprintf(
                    'WP Core Installer: %s has an unknown option "%s" (expected to, mode, gitignore, comment).',
                    $label,
                    (string) $option
                ));
            }
        }

        $mode = self::requireString($options['mode'] ?? 'overwrite', $label . '.mode');
        if (!in_array($mode, self::COPY_MODES, true)) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: %s.mode must be one of %s, "%s" given.',
                $label,
                implode(', ', self::COPY_MODES),
                $mode
            ));
        }

        if (($mode === 'append' || $mode === 'prepend') && $path === '') {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: %s: mode "%s" works on a single file, e.g. "%s:path/to/file".',
                $label,
                $mode,
                $package
            ));
        }

        $gitignore = $options['gitignore'] ?? ($mode === 'overwrite');
        if (!is_bool($gitignore)) {
            throw new \UnexpectedValueException(
                sprintf('WP Core Installer: %s.gitignore must be true or false.', $label)
            );
        }

        $raw = trim(str_replace('\\', '/', self::requireString($options['to'] ?? null, $label . '.to')));
        [$resolved, $isPlaceholderOnly] = $this->resolveCopyDestination($raw, $label, $fromPackage);
        $isDir = $path === '' || $isPlaceholderOnly || $raw === '' || $raw === '.' || str_ends_with($raw, '/');

        return [
            'package'   => $package,
            'path'      => $path,
            'dir'       => $isDir ? $resolved : dirname($resolved),
            'name'      => $path === '' ? '' : ($isDir ? basename($path) : basename($resolved)),
            'mode'      => $mode,
            'gitignore' => $gitignore,
            'comment'   => self::requireString($options['comment'] ?? '#', $label . '.comment'),
        ];
    }

    /**
     * Resolve a copy-to destination, expanding a leading placeholder.
     *
     * @return array{string, bool} Absolute path, and whether it was a bare placeholder.
     */
    private function resolveCopyDestination(string $raw, string $label, bool $fromPackage): array
    {
        $placeholders = [
            '[project-root]' => $this->projectRoot(),
            '[web-root]'     => $this->webRoot(),
            '[wp-content]'   => $this->resolve($this->webRoot(), 'wp-content'),
            '[mu-plugins]'   => $this->muPluginsDir(),
            '[plugins]'      => $this->resolve($this->webRoot(), 'wp-content/plugins'),
            '[themes]'       => $this->resolve($this->webRoot(), 'wp-content/themes'),
        ];

        if (preg_match('{^(\[[a-z-]+\])(/.*)?$}', $raw, $match) === 1) {
            if (!isset($placeholders[$match[1]])) {
                throw new \UnexpectedValueException(sprintf(
                    'WP Core Installer: %s uses an unknown placeholder %s (expected %s).',
                    $label,
                    $match[1],
                    implode(', ', array_keys($placeholders))
                ));
            }

            $rest     = trim($match[2] ?? '', '/');
            $resolved = $this->resolve($placeholders[$match[1]], $rest === '' ? '.' : $rest);
            $bare     = $rest === '';
        } elseif ($fromPackage) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: %s must start with a placeholder such as [mu-plugins]/ or [web-root]/, "%s" given.',
                $label,
                $raw
            ));
        } else {
            $resolved = $this->resolve($this->projectRoot(), $raw);
            $bare     = false;
        }

        $insideProject = $resolved === $this->projectRoot() || str_starts_with($resolved, $this->projectRoot() . '/');

        if ($fromPackage && !$insideProject) {
            throw new \UnexpectedValueException(
                sprintf('WP Core Installer: %s must stay inside the project, "%s" given.', $label, $raw)
            );
        }

        return [$resolved, $bare];
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
     * A boolean setting from extra.wp-core-installer, or $default when unset.
     */
    public function configBool(string $key, bool $default): bool
    {
        $value = $this->pluginConfig()[$key] ?? $default;

        if (!is_bool($value)) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: extra.wp-core-installer.%s in composer.json must be true or false, %s given.',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * Relative path from directory $fromDir to $to, for __DIR__-relative
     * references in generated PHP files. Both must be absolute and
     * normalised (as returned by this class).
     *
     *   relativePath('/app/web', '/app/vendor/autoload.php') → '../vendor/autoload.php'
     *   relativePath('/app/web', '/app')                     → '..'
     *   relativePath('/app', '/app')                         → ''
     */
    public static function relativePath(string $fromDir, string $to): string
    {
        $segments = static fn (string $path): array => array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== ''
        ));
        $from     = $segments($fromDir);
        $target   = $segments($to);

        while ($from !== [] && $target !== [] && $from[0] === $target[0]) {
            array_shift($from);
            array_shift($target);
        }

        return implode('/', array_merge(array_fill(0, count($from), '..'), $target));
    }

    /**
     * Bundled themes / plugins from extra.wp-core-installer.deploy-bundled,
     * as web-root-relative paths such as "wp-content/themes/twentytwentyfive"
     * or "wp-content/plugins/hello.php".
     *
     * Each name must be a single directory or file name inside
     * wp-content/themes or wp-content/plugins.
     *
     * @return string[]
     */
    public function bundledPaths(): array
    {
        $setting = $this->pluginConfig()['deploy-bundled'] ?? [];
        $label   = 'extra.wp-core-installer.deploy-bundled';

        if (!is_array($setting)) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: %s in composer.json must be an object like {"themes": [...], "plugins": [...]}.',
                $label
            ));
        }

        $paths = [];

        foreach ($setting as $kind => $names) {
            if ($kind !== 'themes' && $kind !== 'plugins') {
                throw new \UnexpectedValueException(sprintf(
                    'WP Core Installer: %s only accepts "themes" and "plugins", not "%s".',
                    $label,
                    (string) $kind
                ));
            }

            if (!is_array($names)) {
                throw new \UnexpectedValueException(
                    sprintf('WP Core Installer: %s.%s in composer.json must be an array of strings.', $label, $kind)
                );
            }

            foreach ($names as $name) {
                $name = self::requireString($name, $label . '.' . $kind . '[]');

                if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, '/\\') !== false) {
                    throw new \UnexpectedValueException(sprintf(
                        'WP Core Installer: "%s" in %s.%s must be a single theme or plugin name (e.g. "akismet").',
                        $name,
                        $label,
                        $kind
                    ));
                }

                $paths[] = 'wp-content/' . $kind . '/' . $name;
            }
        }

        return array_values(array_unique($paths));
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
