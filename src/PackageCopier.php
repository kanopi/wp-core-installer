<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * Copies a package's files into a directory that other files share
 * (extra.wp-core-installer.copy-to), e.g. the Kinsta MU plugin into
 * wp-content/mu-plugins/.
 *
 * Why not install the package there directly? Composer treats a package's
 * install folder as its own: it empties the folder on install and deletes it
 * on update or removal. Pointed at mu-plugins/, that wipes every other
 * mu-plugin (see #46). Instead, the package installs into its own folder
 * (e.g. under vendor/) and this class copies its files across:
 *
 *   - only new or changed files are written;
 *   - files the package no longer ships are deleted, as are all its files
 *     when it is removed or dropped from copy-to;
 *   - a manifest per package records exactly which files were placed, and
 *     nothing outside that record is ever overwritten or deleted: an
 *     existing file with different content is skipped with a warning.
 *
 *   "extra": {
 *       "installer-paths": {
 *           "public/wp-content/mu-plugins/vendor/kinsta/kinsta-mu-plugins/": ["kinsta/kinsta-mu-plugins"]
 *       },
 *       "wp-core-installer": {
 *           "copy-to": { "kinsta/kinsta-mu-plugins": "wp-content/mu-plugins" }
 *       }
 *   }
 *
 * Targets are relative to the web-root (or absolute). A top-level
 * composer.json in the package is not copied.
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
     * Bring every copy-to target in line with its installed package, and
     * clean up after packages that were removed or dropped from copy-to.
     */
    public function run(): void
    {
        $targets   = $this->paths->copyTargets();
        $installed = [];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $installed[strtolower($package->getName())] = $package;
        }

        foreach ($targets as $name => $target) {
            if (isset($installed[$name])) {
                $this->copyPackage($installed[$name], $target);
            } else {
                $this->removePlaced($name, 'it is not installed');
            }
        }

        foreach ($this->recordedPackages() as $name) {
            if (!isset($targets[$name])) {
                $this->removePlaced($name, 'it is no longer listed in copy-to');
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

        foreach ($this->recordedPackages() as $name) {
            $manifest = DeployManifest::load($this->manifestPath($name));

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

    private function copyPackage(PackageInterface $package, string $target): void
    {
        $name   = strtolower($package->getName());
        $raw    = (string) $this->composer->getInstallationManager()->getInstallPath($package);
        $source = str_replace('\\', '/', realpath($raw) ?: $raw);

        if (!is_dir($source)) {
            $this->io->writeError(sprintf(
                '  - <warning>copy-to: %s has no files at %s; skipping.</warning>',
                $package->getPrettyName(),
                $source
            ));
            return;
        }

        $files = $this->sourceFiles($source);

        if (($problem = $this->overlapProblem($source, $target, $files)) !== null) {
            $this->io->writeError(sprintf(
                "  - <warning>copy-to: not copying %s. %s</warning>\n"
                . "    Point its installer-paths entry at a folder of its own, e.g. inside vendor-dir.",
                $package->getPrettyName(),
                $problem
            ));
            return;
        }

        $previous = DeployManifest::load($this->manifestPath($name));
        $owned    = array_fill_keys($previous->files ?? [], true);
        $placed   = [];
        $skipped  = [];
        $counts   = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($files as $relative) {
            $from = $source . '/' . $relative;
            $to   = $target . '/' . $relative;

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
        foreach (array_diff(array_keys($owned), $files) as $stale) {
            $removed += $this->deletePlaced($target, $stale) ? 1 : 0;
        }

        $manifest = new DeployManifest(
            $name,
            $package->getVersion(),
            (string) ($package->getDistReference() ?? $package->getSourceReference() ?? ''),
            $target,
            '',
            $placed
        );
        if (!$manifest->save($this->manifestPath($name))) {
            $this->io->writeError(sprintf('  - <warning>copy-to: could not record files for %s.</warning>', $name));
        }

        $this->io->write(sprintf(
            '  - copy-to: %s → %s: %d created, %d updated, %d unchanged%s.',
            $package->getPrettyName(),
            $this->display($target),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $removed > 0 ? sprintf(', %d removed', $removed) : ''
        ));

        if ($skipped !== []) {
            $this->io->writeError(sprintf(
                "  - <warning>copy-to: %d file(s) from %s already exist in %s and were not placed by it;"
                . " left untouched:</warning>\n"
                . "      %s\n"
                . "    If they are an older copy of the package (e.g. previously committed), delete them once"
                . " so it can take over.",
                count($skipped),
                $package->getPrettyName(),
                $this->display($target),
                implode("\n      ", array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? "\n      …" : '')
            ));
        }
    }

    /**
     * Why copying $source into $target would damage the package itself, if
     * it would: copying onto its own install folder, or into a path that
     * contains it (the next copy would recurse into, or overwrite, itself).
     *
     * @param string[] $files
     */
    private function overlapProblem(string $source, string $target, array $files): ?string
    {
        if ($source === $target) {
            return sprintf(
                'It is installed into %s itself, which Composer empties and deletes.',
                $this->display($target)
            );
        }

        foreach (array_unique(array_map(static fn (string $f): string => explode('/', $f)[0], $files)) as $top) {
            $destination = $target . '/' . $top;

            if ($source === $destination || str_starts_with($source . '/', $destination . '/')) {
                return sprintf('Its files would be copied over its own install folder (%s).', $this->display($source));
            }
        }

        return null;
    }

    /**
     * @return string[] Package-relative file paths, forward slashes, sorted.
     */
    private function sourceFiles(string $source): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1);

            if ($file->isFile() && $relative !== 'composer.json') {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Remove everything recorded for $name (package gone or unlisted).
     */
    private function removePlaced(string $name, string $reason): void
    {
        $manifest = DeployManifest::load($this->manifestPath($name));

        if ($manifest === null) {
            return;
        }

        $removed = 0;
        foreach ($manifest->files as $file) {
            $removed += $this->deletePlaced($manifest->webRoot, $file) ? 1 : 0;
        }

        @unlink($this->manifestPath($name));

        $this->io->write(sprintf(
            '  - copy-to: removed %d file(s) placed for %s from %s, because %s.',
            $removed,
            $name,
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
     * Package names that currently have a copy-to manifest.
     *
     * @return string[]
     */
    private function recordedPackages(): array
    {
        $names = [];

        foreach (glob($this->manifestDir() . '/*.json') ?: [] as $file) {
            $manifest = DeployManifest::load($file);
            if ($manifest !== null) {
                $names[] = $manifest->package;
            }
        }

        return $names;
    }

    private function manifestDir(): string
    {
        return $this->paths->vendorDir() . '/.wordpress-core-staging/copy-to';
    }

    private function manifestPath(string $name): string
    {
        return $this->manifestDir() . '/' . str_replace('/', '--', $name) . '.json';
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
