<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\IO\IOInterface;

/**
 * Manages two clearly-marked blocks inside the project's .gitignore file.
 *
 * Everything outside the managed blocks is left completely untouched.
 * Each block is identified by begin/end sentinel comments keyed by a
 * block ID, so blocks can be updated or removed independently.
 *
 * Block IDs used by this plugin:
 *   "core"     — WordPress core files deployed by CoreInstaller.
 *   "packages" — Composer-managed plugins, themes, and the vendor dir.
 *
 * Example output in .gitignore
 * ─────────────────────────────
 *
 *   # <kanopi/wp-core-installer:core:begin>
 *   # Managed by kanopi/wp-core-installer — do not edit this block manually.
 *
 *   # WordPress core staging directory (Composer internal — do not commit)
 *   /wp-content/mu-plugins/vendor/.wordpress-core-staging/
 *
 *   # WordPress core files
 *   /wp-admin/
 *   /wp-includes/
 *   /index.php
 *   …
 *   # <kanopi/wp-core-installer:core:end>
 *
 *   # <kanopi/wp-core-installer:packages:begin>
 *   # Managed by kanopi/wp-core-installer — do not edit this block manually.
 *
 *   # Composer vendor directory
 *   /wp-content/mu-plugins/vendor/
 *
 *   # Composer-managed WordPress plugins
 *   /wp-content/plugins/akismet/
 *   /wp-content/plugins/woocommerce/
 *
 *   # Composer-managed WordPress themes
 *   /wp-content/themes/twentytwentyfive/
 *   # <kanopi/wp-core-installer:packages:end>
 */
class GitignoreManager
{
    /**
     * Block header line (second line inside the sentinel pair).
     */
    private const BLOCK_HEADER = '# Managed by kanopi/wp-core-installer — do not edit this block manually.';

    private const CORE_NEVER_IGNORE = [
        // Project manifests — must always be tracked.
        'composer.json',
        'composer.lock',
        // wp-content is excluded; individual managed packages within it are
        // handled separately in the packages block.
        'wp-content',
        // User-configurable server config and WP reference file.
        '.htaccess',
        // The mu-plugins directory is excluded — the autoloader mu-plugin and
        // any user-owned files inside it must remain tracked.  The vendor dir
        // inside mu-plugins is covered in the packages block.
        'wp-content/mu-plugins',
    ];

    public function __construct(private readonly IOInterface $io)
    {
    }

    // -------------------------------------------------------------------------
    // Public API — Core block
    // -------------------------------------------------------------------------

    /**
     * Rebuild the "core" block in .gitignore.
     *
     * @param string              $projectRoot   Absolute path to the project root.
     * @param string   $webRoot       Absolute path where WP core was deployed.
     * @param string[] $deployedFiles Normalised relative paths (from web-root) of every
     *                                file written during deployment, including skip-if-exists
     *                                files that already existed on disk.
     * @param string   $vendorDirAbs  Absolute path to the Composer vendor dir.
     */
    public function updateCoreBlock(
        string $projectRoot,
        string $webRoot,
        array $deployedFiles,
        string $vendorDirAbs
    ): void {
        $lines = $this->buildCoreBlockLines($projectRoot, $webRoot, $deployedFiles, $vendorDirAbs);
        $this->writeBlock($projectRoot, 'core', $lines);
    }

    /**
     * Remove the "core" block from .gitignore.
     */
    public function removeCoreBlock(string $projectRoot): void
    {
        $this->removeBlock($projectRoot, 'core');
    }

    // -------------------------------------------------------------------------
    // Public API — Packages block
    // -------------------------------------------------------------------------

    /**
     * Rebuild the "packages" block in .gitignore.
     *
     * @param string                  $projectRoot     Absolute path to the project root.
     * @param string                  $vendorDirAbs    Absolute path to the Composer vendor dir.
     * @param array<string, string[]> $byType          Package paths grouped by WP type label.
     * @param string|null             $muPluginFileAbs Absolute path to the managed autoloader
     *                                                 mu-plugin file, or null when opted out.
     */
    public function updatePackagesBlock(
        string $projectRoot,
        string $vendorDirAbs,
        array $byType,
        ?string $muPluginFileAbs = null
    ): void {
        $lines = $this->buildPackagesBlockLines($projectRoot, $vendorDirAbs, $byType, $muPluginFileAbs);
        $this->writeBlock($projectRoot, 'packages', $lines);
    }

    /**
     * Remove the "packages" block from .gitignore.
     */
    public function removePackagesBlock(string $projectRoot): void
    {
        $this->removeBlock($projectRoot, 'packages');
    }

    // -------------------------------------------------------------------------
    // Block builders
    // -------------------------------------------------------------------------

    /**
     * Build lines for the "core" managed block.
     *
     * Collapses the deployed file list into the most concise set of gitignore
     * patterns that covers every deployed path exactly:
     *
     *   - Top-level entries NOT in NEVER_IGNORE → emit as /name or /name/
     *     (one rule covers the whole directory tree)
     *   - Top-level entries IN NEVER_IGNORE (e.g. wp-content) → emit only the
     *     specific files that were deployed inside them, so unmanaged sibling
     *     files in the same directory remain tracked.
     *
     * @param string[] $deployedFiles  Normalised relative paths (forward slashes,
     *                                 no leading slash) of every file written.
     * @return string[]
     */
    private function buildCoreBlockLines(
        string $projectRoot,
        string $webRoot,
        array $deployedFiles,
        string $vendorDirAbs
    ): array {
        $webPrefix       = $this->relativePrefix($projectRoot, $webRoot);
        $vendorRelative  = $this->relativeToProject($projectRoot, $vendorDirAbs);
        $stagingRelative = $vendorRelative . '/.wordpress-core-staging';

        $lines   = [];
        $lines[] = '';
        $lines[] = '# WordPress core staging directory (Composer internal — do not commit)';
        $lines[] = '/' . $stagingRelative . '/';
        $lines[] = '';
        $lines[] = '# WordPress core files (managed via Composer — do not commit)';

        // Group deployed paths by their first path segment.
        // e.g. "wp-admin/load.php" → group "wp-admin"
        //      "wp-content/index.php" → group "wp-content"
        //      "index.php"            → group "index.php"
        /** @var array<string, list<string>> $groups */
        $groups = [];
        foreach ($deployedFiles as $path) {
            $slash   = strpos($path, '/');
            $segment = $slash !== false ? substr($path, 0, $slash) : $path;
            $groups[$segment][] = $path;
        }

        ksort($groups);

        $entries = [];

        foreach ($groups as $segment => $paths) {
            if (in_array($segment, self::CORE_NEVER_IGNORE, true)) {
                // Partially-managed directory: emit each deployed file explicitly
                // so that unmanaged siblings stay tracked in git.
                foreach ($paths as $path) {
                    $entries[] = '/' . $webPrefix . $path;
                }
            } else {
                // Fully-managed entry: one pattern covers the whole thing.
                // Use a trailing slash when the segment is a directory
                // (any group with more than one file, or whose sole file contains a slash).
                $isDir = count($paths) > 1
                    || strpos($paths[0], '/') !== false;

                $entries[] = '/' . $webPrefix . $segment . ($isDir ? '/' : '');
            }
        }

        // De-duplicate (skip-if-exists files appear once, but be safe).
        $entries = array_values(array_unique($entries));
        sort($entries);

        foreach ($entries as $entry) {
            $lines[] = $entry;
        }

        return $lines;
    }

    /**
     * @param array<string, string[]> $byType
     * @return string[]
     */
    private function buildPackagesBlockLines(
        string $projectRoot,
        string $vendorDirAbs,
        array $byType,
        ?string $muPluginFileAbs = null
    ): array {
        $vendorRelative = $this->relativeToProject($projectRoot, $vendorDirAbs);

        $lines   = [];
        $lines[] = '';
        $lines[] = '# Composer vendor directory';
        $lines[] = '/' . $vendorRelative . '/';

        // If the autoloader mu-plugin is managed, gitignore it — it is always
        // regenerated by Composer just like vendor/ itself.
        if ($muPluginFileAbs !== null) {
            $muPluginRelative = $this->relativeToProject($projectRoot, $muPluginFileAbs);

            if ($muPluginRelative !== $muPluginFileAbs) {
                $lines[] = '';
                $lines[] = '# Composer autoloader mu-plugin (regenerated on every composer install)';
                $lines[] = '/' . str_replace('\\', '/', $muPluginRelative);
            }
        }

        // Type label => human-readable section heading.
        $headings = [
            'plugins'    => 'Composer-managed WordPress plugins',
            'themes'     => 'Composer-managed WordPress themes',
            'mu-plugins' => 'Composer-managed WordPress must-use plugins',
        ];

        foreach ($headings as $typeKey => $heading) {
            $paths = $byType[$typeKey] ?? [];

            if (empty($paths)) {
                continue;
            }

            sort($paths);

            $lines[] = '';
            $lines[] = '# ' . $heading;

            foreach ($paths as $path) {
                // Paths are relative, no leading slash; trailing slash marks dirs.
                $lines[] = '/' . rtrim(str_replace('\\', '/', $path), '/') . '/';
            }
        }

        return $lines;
    }

    // -------------------------------------------------------------------------
    // Generic block write / remove
    // -------------------------------------------------------------------------

    /**
     * @param string[] $contentLines  Lines that go between the sentinels
     *                                (without the begin/end markers themselves).
     */
    private function writeBlock(string $projectRoot, string $blockId, array $contentLines): void
    {
        $path    = $projectRoot . DIRECTORY_SEPARATOR . '.gitignore';
        $current = $this->read($path);
        $block   = $this->renderBlock($blockId, $contentLines);
        $updated = $this->replaceOrAppend($current, $blockId, $block);

        if ($updated === $current) {
            $this->io->write(
                sprintf('  - <comment>.gitignore "%s" block already up to date.</comment>', $blockId),
                true,
                IOInterface::VERBOSE
            );
            return;
        }

        if (file_put_contents($path, $updated) === false) {
            $this->io->writeError(
                sprintf(
                    '  - <error>WP Core Installer: failed to write .gitignore at %s</error>',
                    $path
                )
            );
            return;
        }

        $this->io->write(
            sprintf('  - <info>.gitignore</info> "%s" block refreshed.', $blockId)
        );
    }

    private function removeBlock(string $projectRoot, string $blockId): void
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . '.gitignore';

        if (!file_exists($path)) {
            return;
        }

        $current = $this->read($path);
        $updated = $this->stripBlock($current, $blockId);

        if ($updated === $current) {
            return;
        }

        $trimmed = trim($updated);

        if ($trimmed === '') {
            unlink($path);
            $this->io->write(
                sprintf('  - <info>.gitignore</info> "%s" block removed (file was empty, deleted).', $blockId)
            );
            return;
        }

        file_put_contents($path, $updated);
        $this->io->write(
            sprintf('  - <info>.gitignore</info> "%s" block removed.', $blockId)
        );
    }

    // -------------------------------------------------------------------------
    // Rendering helpers
    // -------------------------------------------------------------------------

    /**
     * Render the full text of a managed block, including sentinels and header.
     *
     * @param string[] $contentLines
     */
    private function renderBlock(string $blockId, array $contentLines): string
    {
        $parts = [];
        $parts[] = $this->beginSentinel($blockId);
        $parts[] = self::BLOCK_HEADER;

        foreach ($contentLines as $line) {
            $parts[] = $line;
        }

        // Ensure block ends with a blank line before the closing sentinel
        // for readability, then the sentinel itself.
        if (end($parts) !== '') {
            $parts[] = '';
        }

        $parts[] = $this->endSentinel($blockId);

        return implode("\n", $parts);
    }

    private function replaceOrAppend(string $existing, string $blockId, string $newBlock): string
    {
        $pattern = $this->blockPattern($blockId);

        if (preg_match($pattern, $existing)) {
            return (string) preg_replace($pattern, $newBlock, $existing);
        }

        // No existing block — append with one blank line of separation.
        $separator = (strlen(trim($existing)) > 0 && !str_ends_with($existing, "\n\n")) ? "\n" : '';

        return $existing . $separator . "\n" . $newBlock . "\n";
    }

    private function stripBlock(string $content, string $blockId): string
    {
        $inner   = substr($this->blockPattern($blockId), 1, -1);
        $pattern = '/\n?' . $inner . '\n?/s';

        return (string) preg_replace($pattern, "\n", $content);
    }

    private function blockPattern(string $blockId): string
    {
        return '/'
            . preg_quote($this->beginSentinel($blockId), '/')
            . '.*?'
            . preg_quote($this->endSentinel($blockId), '/')
            . '/s';
    }

    private function beginSentinel(string $blockId): string
    {
        return sprintf('# <kanopi/wp-core-installer:%s:begin>', $blockId);
    }

    private function endSentinel(string $blockId): string
    {
        return sprintf('# <kanopi/wp-core-installer:%s:end>', $blockId);
    }

    // -------------------------------------------------------------------------
    // Filesystem / path helpers
    // -------------------------------------------------------------------------

    private function read(string $path): string
    {
        if (!file_exists($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return $content !== false ? $content : '';
    }

    /**
     * Return the path segment that should prefix gitignore entries for files
     * inside $absDir, relative to $projectRoot.
     *
     * Examples:
     *   project=/var/www, dir=/var/www           → ""
     *   project=/var/www, dir=/var/www/public/wp → "public/wp/"
     */
    private function relativePrefix(string $projectRoot, string $absDir): string
    {
        $rel = $this->relativeToProject($projectRoot, $absDir);

        return $rel === '' ? '' : $rel . '/';
    }

    /**
     * Return $absPath relative to $projectRoot (forward slashes, no leading slash).
     * Returns '' when they are the same directory.
     */
    public function relativeToProject(string $projectRoot, string $absPath): string
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $absPath     = rtrim(str_replace('\\', '/', $absPath), '/');

        if ($absPath === $projectRoot) {
            return '';
        }

        if (str_starts_with($absPath, $projectRoot . '/')) {
            return substr($absPath, strlen($projectRoot) + 1);
        }

        // Path is outside the project root — return as-is (best effort).
        return $absPath;
    }
}