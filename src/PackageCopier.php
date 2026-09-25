<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * Copies files out of installed packages into folders other files share
 * (extra.wp-core-installer.copy-to).
 *
 * WordPress only loads some files from fixed, shared places: must-use
 * plugins from the top of wp-content/mu-plugins/, drop-ins such as
 * object-cache.php from wp-content/. A package can't be *installed* there,
 * because Composer treats a package's install folder as its own (it empties
 * it on install and deletes it on update or removal; see #46). So the
 * package installs normally and the files that must live elsewhere are
 * copied out:
 *
 *   "copy-to": {
 *       "acme/some-mu-plugin:acme-loader.php":                   "web/wp-content/mu-plugins/",
 *       "wpackagist-plugin/redis-cache:includes/object-cache.php": "web/wp-content/object-cache.php",
 *       "acme/tools:assets":                                       "web/app/acme-assets",
 *       "acme/mu-bundle":                                          "web/wp-content/mu-plugins"
 *   }
 *
 * Works for any package type. A "vendor/package:path" entry copies that
 * file or folder: a destination ending in "/" is a folder to copy it into
 * (keeping its name), anything else is the exact destination path (so it
 * can be renamed). A bare "vendor/package" entry copies all of the
 * package's top-level entries except composer.json into the destination
 * folder. Destinations are relative to the project root, like
 * installer-paths.
 *
 * Safety:
 *   - only new or changed files are written;
 *   - each entry has a manifest recording exactly what it placed; files it
 *     stops placing are deleted, as is everything it placed when the entry
 *     or the package goes away;
 *   - nothing outside that record is ever overwritten or deleted: an
 *     existing file with identical content is adopted, one with different
 *     content is skipped with a warning;
 *   - an entry whose copy would land on the package's own install folder is
 *     refused.
 */
class PackageCopier
{
    private Filesystem $filesystem;
    private ProjectPaths $paths;

    public function __construct(
        private Composer $composer,
        private IOInterface $io
    ) {
        $this->filesystem = new Filesystem();
        $this->paths      = new ProjectPaths($composer);
    }

    /**
     * Bring every copy-to entry in line with its installed package, and clean
     * up after entries whose package was removed or that were dropped.
     */
    public function run(): void
    {
        $entries   = $this->paths->copyTargets();
        $installed = [];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $installed[strtolower($package->getName())] = $package;
        }

        foreach ($entries as $key => $entry) {
            if (isset($installed[$entry['package']])) {
                $this->copyEntry($key, $installed[$entry['package']], $entry['path'], $entry['dir'], $entry['name']);
            } else {
                $this->removePlaced($key, sprintf('%s is not installed', $entry['package']));
            }
        }

        foreach ($this->recordedEntries() as $key) {
            if (!isset($entries[$key])) {
                $this->removePlaced($key, 'it is no longer listed in copy-to');
            }
        }
    }

    /**
     * Top-level paths currently placed by copy-to, for .gitignore:
     * absolute path => whether it is a directory.
     *
     * @return array<string, bool>
     */
    public function placedTopLevel(): array
    {
        $entries = [];

        foreach ($this->recordedEntries() as $key) {
            $manifest = DeployManifest::load($this->manifestPath($key));

            if ($manifest === null) {
                continue;
            }

            foreach ($manifest->files as $file) {
                $slash = strpos($file, '/');
                $top   = $manifest->webRoot . '/' . ($slash === false ? $file : substr($file, 0, $slash));

                $entries[$top] = ($entries[$top] ?? false) || $slash !== false;
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @param string $path   Path inside the package ('' = the whole package).
     * @param string $target Absolute folder the copy lands in.
     * @param string $name   Name of the copied entry inside $target ('' for a whole package).
     */
    private function copyEntry(string $key, PackageInterface $package, string $path, string $target, string $name): void
    {
        $raw  = (string) $this->composer->getInstallationManager()->getInstallPath($package);
        $base = rtrim(str_replace('\\', '/', realpath($raw) ?: $raw), '/');

        if (!is_dir($base)) {
            $this->io->writeError(sprintf(
                '  - <warning>copy-to: %s has no files at %s; skipping %s.</warning>',
                $package->getPrettyName(),
                $base,
                $key
            ));
            return;
        }

        $sources = $this->sourcesFor($base, $path, $name);

        if ($sources === null) {
            $this->io->writeError(sprintf(
                '  - <warning>copy-to: %s does not contain "%s"; nothing to copy for %s.</warning>',
                $package->getPrettyName(),
                $path,
                $key
            ));
            $sources = [];
        }

        if (($problem = $this->overlapProblem($base, $target, array_keys($sources))) !== null) {
            $this->io->writeError(sprintf(
                "  - <warning>copy-to: not copying %s. %s</warning>\n"
                . "    Install the package somewhere else (installer-paths), or copy a file out of it instead.",
                $key,
                $problem
            ));
            return;
        }

        $previous = DeployManifest::load($this->manifestPath($key));
        $owned    = array_fill_keys($previous->files ?? [], true);
        $placed   = [];
        $skipped  = [];
        $counts   = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($sources as $relative => $from) {
            $to = $target . '/' . $relative;

            if (is_file($to) && $this->sameContent($from, $to)) {
                $placed[] = $relative;
                $counts['unchanged']++;
                continue;
            }

            if (file_exists($to) && (!isset($owned[$relative]) || !is_file($to))) {
                $skipped[] = $relative;
                continue;
            }

            $existed = is_file($to);
            $this->filesystem->ensureDirectoryExists(dirname($to));

            if (!@copy($from, $to)) {
                $this->io->writeError(sprintf('  - <error>copy-to: failed to copy %s → %s</error>', $from, $to));
                continue;
            }

            $placed[] = $relative;
            $counts[$existed ? 'updated' : 'created']++;
        }

        $removed = 0;
        foreach (array_diff(array_keys($owned), array_keys($sources)) as $stale) {
            $removed += $this->deletePlaced($target, (string) $stale) ? 1 : 0;
        }

        $manifest = new DeployManifest(
            $key,
            $package->getVersion(),
            (string) ($package->getDistReference() ?? $package->getSourceReference() ?? ''),
            $target,
            '',
            $placed
        );
        if (!$manifest->save($this->manifestPath($key))) {
            $this->io->writeError(sprintf('  - <warning>copy-to: could not record files for %s.</warning>', $key));
        }

        $this->io->write(sprintf(
            '  - copy-to: %s → %s: %d created, %d updated, %d unchanged%s.',
            $key,
            $this->display($target),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $removed > 0 ? sprintf(', %d removed', $removed) : ''
        ));

        if ($skipped !== []) {
            $this->io->writeError(sprintf(
                "  - <warning>copy-to: %d file(s) for %s already exist in %s and were not placed by it;"
                . " left untouched:</warning>\n"
                . "      %s\n"
                . "    If they are an older copy of the same files, delete them once so copy-to can take over.",
                count($skipped),
                $key,
                $this->display($target),
                implode("\n      ", array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? "\n      …" : '')
            ));
        }
    }

    /**
     * Files to copy for one entry: target-relative path => absolute source.
     * The copied file or folder is named $name in the target (a rename when
     * it differs from basename($path)). Null when $path does not exist.
     *
     * @return array<string, string>|null
     */
    private function sourcesFor(string $base, string $path, string $name): ?array
    {
        if ($path === '') {
            $sources = $this->filesUnder($base, '');
            unset($sources['composer.json']);

            return $sources;
        }

        $source = $base . '/' . $path;

        if (is_file($source)) {
            return [$name => $source];
        }

        if (is_dir($source)) {
            return $this->filesUnder($source, $name . '/');
        }

        return null;
    }

    /**
     * @return array<string, string> "$prefix<relative path>" => absolute path, sorted.
     */
    private function filesUnder(string $dir, string $prefix): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $pathname                  = str_replace('\\', '/', $file->getPathname());
                $files[$prefix . substr($pathname, strlen($dir) + 1)] = $pathname;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Why this copy would damage the package itself, if it would: copying
     * onto (or into a folder containing) its own install folder, or into a
     * target inside the package (every run would copy its own output).
     *
     * @param string[] $relatives Target-relative paths that would be written.
     */
    private function overlapProblem(string $base, string $target, array $relatives): ?string
    {
        if ($base === $target || str_starts_with($target . '/', $base . '/')) {
            return sprintf('The target %s is inside the package\'s own install folder.', $this->display($target));
        }

        foreach (array_unique(array_map(static fn (string $f): string => explode('/', $f)[0], $relatives)) as $top) {
            $destination = $target . '/' . $top;

            if ($base === $destination || str_starts_with($base . '/', $destination . '/')) {
                return sprintf(
                    'It would be copied over the package\'s own install folder (%s).',
                    $this->display($base)
                );
            }
        }

        return null;
    }

    /**
     * Remove everything recorded for an entry (package gone or entry dropped).
     */
    private function removePlaced(string $key, string $reason): void
    {
        $manifest = DeployManifest::load($this->manifestPath($key));

        if ($manifest === null) {
            return;
        }

        $removed = 0;
        foreach ($manifest->files as $file) {
            $removed += $this->deletePlaced($manifest->webRoot, $file) ? 1 : 0;
        }

        @unlink($this->manifestPath($key));

        $this->io->write(sprintf(
            '  - copy-to: removed %d file(s) placed for %s from %s, because %s.',
            $removed,
            $key,
            $this->display($manifest->webRoot),
            $reason
        ));
    }

    /**
     * Delete one placed file and any directories it leaves empty (never the
     * target directory itself).
     */
    private function deletePlaced(string $target, string $relative): bool
    {
        if ($this->filesystem->isAbsolutePath($relative) || in_array('..', explode('/', $relative), true)) {
            return false;
        }

        $path = $target . '/' . $relative;

        if (!is_file($path) || !@unlink($path)) {
            return false;
        }

        $dir = dirname($path);
        while (
            str_starts_with($dir, $target . '/')
            && is_dir($dir)
            && $this->filesystem->isDirEmpty($dir)
            && @rmdir($dir)
        ) {
            $dir = dirname($dir);
        }

        return true;
    }

    /**
     * copy-to entries that currently have a manifest.
     *
     * @return string[]
     */
    private function recordedEntries(): array
    {
        $keys = [];

        foreach (glob($this->manifestDir() . '/*.json') ?: [] as $file) {
            $manifest = DeployManifest::load($file);
            if ($manifest !== null) {
                $keys[] = $manifest->package;
            }
        }

        return $keys;
    }

    private function manifestDir(): string
    {
        return $this->paths->vendorDir() . '/.wordpress-core-staging/copy-to';
    }

    private function manifestPath(string $key): string
    {
        return $this->manifestDir() . '/' . rawurlencode($key) . '.json';
    }

    private function sameContent(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && md5_file($a) === md5_file($b);
    }

    private function display(string $path): string
    {
        $relative = ProjectPaths::relativePath($this->paths->projectRoot(), $path);

        return $relative === '' || str_starts_with($relative, '..') ? $path : $relative;
    }
}
