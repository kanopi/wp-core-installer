<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * Copies files out of installed packages to other places in the project
 * (copy-to).
 *
 * Typical uses are files WordPress only loads from fixed, shared places —
 * top-level mu-plugin loaders, drop-ins such as object-cache.php — which a
 * package can't be *installed* into, because Composer treats a package's
 * install folder as its own and empties / deletes it (#46). The package
 * installs normally; copy-to places the files that must live elsewhere.
 *
 * Entries come from the project (extra.wp-core-installer.copy-to) and from
 * allowed packages' own extra.wp-core-installer.copy-to; see
 * ProjectPaths::copyTargets() for the syntax and placeholders. Modes:
 *
 *   overwrite   Default. The destination always matches the package: new and
 *               changed files are written (an existing file is taken over),
 *               files the package stops shipping are deleted, and everything
 *               placed is removed when the entry or the package goes away.
 *               Files or folders.
 *   if-missing  Copied only when the destination doesn't exist; never
 *               updated or removed afterwards (the project owns it).
 *               Files or folders.
 *   append /    The source file's contents are kept in a marked section at
 *   prepend     the end / start of the destination file; only that section
 *               is ever rewritten or removed. Files only.
 *
 * Each entry keeps a CopyRecord of what it did, so nothing outside it is
 * touched, and changing an entry's mode or destination first undoes the old
 * one. A copy that would land on the package's own install folder is refused.
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
            $previous = CopyRecord::load($this->recordPath($key));

            // A changed mode or destination: undo the old placement first.
            if ($previous !== null && ($previous->mode !== $entry['mode'] || $previous->dir !== $entry['dir'])) {
                $this->undo($previous, 'its mode or destination changed');
                $previous = null;
            }

            if (isset($installed[$entry['package']])) {
                $this->copyEntry($key, $entry, $installed[$entry['package']], $previous);
            } elseif ($previous !== null) {
                $this->undo($previous, sprintf('%s is not installed', $entry['package']));
            }
        }

        foreach ($this->records() as $record) {
            if (!isset($entries[$record->key])) {
                $this->undo($record, 'it is no longer listed in copy-to');
            }
        }
    }

    /**
     * Top-level paths copy-to has placed and should gitignore:
     * absolute path => whether it is a directory.
     *
     * @return array<string, bool>
     */
    public function placedTopLevel(): array
    {
        $entries = [];

        foreach ($this->records() as $record) {
            if (!$record->gitignore) {
                continue;
            }

            foreach ($record->files as $file) {
                $slash = strpos($file, '/');
                $top   = $record->dir . '/' . ($slash === false ? $file : substr($file, 0, $slash));

                $entries[$top] = ($entries[$top] ?? false) || $slash !== false;
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @param array{package: string, path: string, dir: string, name: string,
     *              mode: string, gitignore: bool, comment: string, source: string} $entry
     */
    private function copyEntry(string $key, array $entry, PackageInterface $package, ?CopyRecord $previous): void
    {
        $raw  = (string) $this->composer->getInstallationManager()->getInstallPath($package);
        $base = rtrim(str_replace('\\', '/', realpath($raw) ?: $raw), '/');

        if (!is_dir($base)) {
            $this->warn(sprintf('%s has no files at %s; skipping %s.', $package->getPrettyName(), $base, $key));
            return;
        }

        $isSection = $entry['mode'] === 'append' || $entry['mode'] === 'prepend';

        if ($isSection && !is_file($base . '/' . $entry['path'])) {
            $this->warn(sprintf(
                '%s: mode "%s" works on a single file, and "%s" is not a file in %s; skipping.',
                $key,
                $entry['mode'],
                $entry['path'],
                $package->getPrettyName()
            ));
            if ($previous !== null) {
                $this->undo($previous, 'its source is no longer a file');
            }
            return;
        }

        $sources = $this->sourcesFor($base, $entry['path'], $entry['name']);

        if ($sources === null) {
            $this->warn(sprintf(
                '%s does not contain "%s"; nothing to copy for %s.',
                $package->getPrettyName(),
                $entry['path'],
                $key
            ));
            $sources = [];
        }

        if (($problem = $this->overlapProblem($base, $entry['dir'], array_keys($sources))) !== null) {
            $this->warn(sprintf(
                "not copying %s. %s\n"
                . "    Install the package somewhere else (installer-paths), or copy a file out of it instead.",
                $key,
                $problem
            ));
            return;
        }

        $record = new CopyRecord($key, $entry['dir'], $entry['mode'], $entry['gitignore'], $entry['comment']);

        if ($isSection) {
            $this->applySection($record, $sources);
        } elseif ($entry['mode'] === 'if-missing') {
            $this->applyIfMissing($record, $sources);
        } else {
            $this->applyOverwrite($record, $sources, $previous);
        }

        if (!$record->save($this->recordPath($key))) {
            $this->warn(sprintf('could not record what was copied for %s.', $key));
        }
    }

    /**
     * @param array<string, string> $sources
     */
    private function applyOverwrite(CopyRecord $record, array $sources, ?CopyRecord $previous): void
    {
        $owned     = array_fill_keys($previous->files ?? [], true);
        $counts    = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $takenOver = [];

        foreach ($sources as $relative => $from) {
            $to = $record->dir . '/' . $relative;

            if (is_file($to) && $this->sameContent($from, $to)) {
                $record->files[] = $relative;
                $counts['unchanged']++;
                continue;
            }

            if (is_dir($to)) {
                $this->warn(sprintf('%s: %s is a directory; not replacing it with a file.', $record->key, $to));
                continue;
            }

            $existed = is_file($to);
            if ($existed && !isset($owned[$relative])) {
                $takenOver[] = $relative;
            }

            if (!$this->copyFile($from, $to)) {
                continue;
            }

            $record->files[] = $relative;
            $counts[$existed ? 'updated' : 'created']++;
        }

        $removed = 0;
        foreach (array_diff(array_keys($owned), array_keys($sources)) as $stale) {
            $removed += $this->deletePlaced($record->dir, (string) $stale) ? 1 : 0;
        }

        $this->io->write(sprintf(
            '  - copy-to: %s → %s: %d created, %d updated, %d unchanged%s.',
            $record->key,
            $this->display($record->dir),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $removed > 0 ? sprintf(', %d removed', $removed) : ''
        ));

        if ($takenOver !== []) {
            $this->io->write(sprintf(
                '  - copy-to: %s replaced existing file(s) it now manages: %s',
                $record->key,
                implode(', ', array_slice($takenOver, 0, 10)) . (count($takenOver) > 10 ? ', …' : '')
            ));
        }
    }

    /**
     * @param array<string, string> $sources
     */
    private function applyIfMissing(CopyRecord $record, array $sources): void
    {
        $created = 0;

        foreach ($sources as $relative => $from) {
            $to = $record->dir . '/' . $relative;

            if (!file_exists($to) && $this->copyFile($from, $to)) {
                $created++;
            }

            // Recorded only for .gitignore (when enabled); if-missing files
            // belong to the project and are never updated or deleted.
            $record->files[] = $relative;
        }

        $this->io->write(sprintf(
            '  - copy-to: %s → %s (if-missing): %d created, %d already present.',
            $record->key,
            $this->display($record->dir),
            $created,
            count($sources) - $created
        ));
    }

    /**
     * @param array<string, string> $sources Exactly one file.
     */
    private function applySection(CopyRecord $record, array $sources): void
    {
        $relative = (string) array_key_first($sources);
        $target   = $record->dir . '/' . $relative;
        $content  = (string) file_get_contents($sources[$relative]);
        $existing = is_file($target) ? (string) file_get_contents($target) : '';
        $updated  = $this->withSection($existing, $record, $content);

        if ($updated !== $existing) {
            $this->filesystem->ensureDirectoryExists(dirname($target));
            if (file_put_contents($target, $updated) === false) {
                $this->warn(sprintf('could not write %s.', $target));
                return;
            }
        }

        $record->files = [$relative];

        $this->io->write(sprintf(
            '  - copy-to: %s → %s (%s): %s.',
            $record->key,
            $this->display($target),
            $record->mode,
            $updated === $existing ? 'unchanged' : 'section updated'
        ));
    }

    /**
     * $existing with this entry's section (re)placed at the end (append) or
     * start (prepend), separated from other content by one blank line.
     */
    private function withSection(string $existing, CopyRecord $record, string $content): string
    {
        $rest    = $this->withoutSection($existing, $record);
        $section = $this->beginMarker($record) . "\n"
            . rtrim($content, "\r\n") . "\n"
            . $this->endMarker($record) . "\n";

        if (trim($rest) === '') {
            return $section;
        }

        return $record->mode === 'prepend'
            ? $section . "\n" . ltrim($rest, "\r\n")
            : rtrim($rest, "\r\n") . "\n\n" . $section;
    }

    /**
     * $existing with this entry's section (and the blank line around it) removed.
     */
    private function withoutSection(string $existing, CopyRecord $record): string
    {
        $pattern = '/(\r?\n)*' . preg_quote($this->beginMarker($record), '/') . '.*?'
            . preg_quote($this->endMarker($record), '/') . '(\r?\n)*/s';

        if (preg_match($pattern, $existing, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $existing;
        }

        [$block, $offset] = $match[0];
        $before = substr($existing, 0, $offset);
        $after  = substr($existing, $offset + strlen($block));

        if (trim($before) === '') {
            return $after;
        }

        return trim($after) === '' ? $before . "\n" : $before . "\n\n" . $after;
    }

    private function beginMarker(CopyRecord $record): string
    {
        return sprintf('%s BEGIN %s (managed by kanopi/wp-core-installer copy-to)', $record->comment, $record->key);
    }

    private function endMarker(CopyRecord $record): string
    {
        return sprintf('%s END %s', $record->comment, $record->key);
    }

    /**
     * Undo what a record describes: delete placed files (overwrite), strip
     * the managed section (append / prepend), or nothing (if-missing).
     */
    private function undo(CopyRecord $record, string $reason): void
    {
        $removed = 0;

        if ($record->mode === 'append' || $record->mode === 'prepend') {
            foreach ($record->files as $file) {
                $target = $record->dir . '/' . $file;
                if (!is_file($target)) {
                    continue;
                }

                $existing = (string) file_get_contents($target);
                $rest     = $this->withoutSection($existing, $record);

                if ($rest === $existing) {
                    continue;
                }

                if (trim($rest) === '') {
                    @unlink($target);
                } else {
                    file_put_contents($target, $rest);
                }
                $removed++;
            }
        } elseif ($record->mode === 'overwrite') {
            foreach ($record->files as $file) {
                $removed += $this->deletePlaced($record->dir, $file) ? 1 : 0;
            }
        }

        @unlink($this->recordPath($record->key));

        $this->io->write(sprintf(
            '  - copy-to: undid %s (%s, %d file(s)), because %s.',
            $record->key,
            $record->mode,
            $removed,
            $reason
        ));
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
                $pathname = str_replace('\\', '/', $file->getPathname());
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

    private function copyFile(string $from, string $to): bool
    {
        $this->filesystem->ensureDirectoryExists(dirname($to));

        if (!@copy($from, $to)) {
            $this->io->writeError(sprintf('  - <error>copy-to: failed to copy %s → %s</error>', $from, $to));
            return false;
        }

        return true;
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
     * @return CopyRecord[]
     */
    private function records(): array
    {
        $records = [];

        foreach (glob($this->recordDir() . '/*.json') ?: [] as $file) {
            $record = CopyRecord::load($file);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function recordDir(): string
    {
        return $this->paths->vendorDir() . '/.wordpress-core-staging/copy-to';
    }

    private function recordPath(string $key): string
    {
        return $this->recordDir() . '/' . rawurlencode($key) . '.json';
    }

    private function sameContent(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && md5_file($a) === md5_file($b);
    }

    private function warn(string $message): void
    {
        $this->io->writeError('  - <warning>copy-to: ' . $message . '</warning>');
    }

    private function display(string $path): string
    {
        $relative = ProjectPaths::relativePath($this->paths->projectRoot(), $path);

        return $relative === '' || str_starts_with($relative, '..') ? $path : $relative;
    }
}
