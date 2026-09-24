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

    /**
     * Top-level web-root directories that also hold project-owned files
     * (themes, plugins, uploads, …). Deployed core files inside them are
     * listed individually instead of ignoring the whole directory, so
     * unmanaged siblings stay tracked.
     */
    private const PARTIALLY_MANAGED_DIRS = [
        'wp-content',
    ];

    /**
     * @param array<mixed> $pluginConfig The root package's extra.wp-core-installer array.
     */
    public function __construct(
        private IOInterface $io,
        private array $pluginConfig = []
    ) {
    }

    /**
     * Whether the given block ("core" or "packages") is managed.
     *
     * extra.wp-core-installer.manage-gitignore accepts:
     *   true / omitted              → both blocks managed (default)
     *   false                       → neither block managed
     *   {"core": false, ...}        → per-block; unlisted blocks default to true
     */
    public function isBlockManaged(string $blockId): bool
    {
        $setting = $this->pluginConfig['manage-gitignore'] ?? true;

        if (is_array($setting)) {
            return (bool) ($setting[$blockId] ?? true);
        }

        return (bool) $setting;
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
     *                                always-synced core file written during deployment
     *                                (skip-if-exists files are excluded).
     * @param string   $vendorDirAbs  Absolute path to the Composer vendor dir.
     */
    public function updateCoreBlock(
        string $projectRoot,
        string $webRoot,
        array $deployedFiles,
        string $vendorDirAbs
    ): void {
        if (!$this->skipUnmanaged($projectRoot, 'core')) {
            $lines = $this->buildCoreBlockLines($projectRoot, $webRoot, $deployedFiles, $vendorDirAbs);
            $this->writeBlock($projectRoot, 'core', $lines);
        }
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
        if (!$this->skipUnmanaged($projectRoot, 'packages')) {
            $lines = $this->buildPackagesBlockLines($projectRoot, $vendorDirAbs, $byType, $muPluginFileAbs);
            $this->writeBlock($projectRoot, 'packages', $lines);
        }
    }

    /**
     * Remove the "packages" block from .gitignore.
     */
    public function removePackagesBlock(string $projectRoot): void
    {
        $this->removeBlock($projectRoot, 'packages');
    }

    /**
     * When a block is opted out, strip any copy left from before the opt-out
     * (so its entries stop hiding files) and tell the caller to skip writing.
     */
    private function skipUnmanaged(string $projectRoot, string $blockId): bool
    {
        if ($this->isBlockManaged($blockId)) {
            return false;
        }

        $this->io->write(
            sprintf('  - <comment>.gitignore "%s" block not managed</comment> (manage-gitignore).', $blockId),
            true,
            IOInterface::VERBOSE
        );
        $this->removeBlock($projectRoot, $blockId);

        return true;
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
     *   - Top-level entries NOT in PARTIALLY_MANAGED_DIRS → emit as /name or /name/
     *     (one rule covers the whole directory tree)
     *   - Top-level entries IN PARTIALLY_MANAGED_DIRS (wp-content) → emit only the
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
        $webPrefix = $this->relativePrefix($projectRoot, $webRoot);
        $lines     = [];

        // Paths outside the project root cannot be expressed in the project's
        // .gitignore (and are not in the repository anyway) — omit them.
        if ($this->isInsideProject($projectRoot, $vendorDirAbs)) {
            $vendorRelative = $this->relativeToProject($projectRoot, $vendorDirAbs);
            $lines[] = '';
            $lines[] = '# WordPress core staging directory (Composer internal — do not commit)';
            $lines[] = '/' . $this->joinRelative($vendorRelative, '.wordpress-core-staging') . '/';
        }

        if (!$this->isInsideProject($projectRoot, $webRoot)) {
            return $lines;
        }

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
            if (in_array($segment, self::PARTIALLY_MANAGED_DIRS, true)) {
                // Partially-managed directory: emit each deployed file explicitly
                // so that unmanaged siblings stay tracked in git. Bundled
                // themes/plugins (deploy-bundled) are whole directories we
                // manage, so each collapses to a single directory rule.
                foreach ($paths as $path) {
                    $entries[] = preg_match('#^(wp-content/(?:themes|plugins)/[^/]+)/#', $path, $bundle) === 1
                        ? '/' . $webPrefix . $bundle[1] . '/'
                        : '/' . $webPrefix . $path;
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

        // De-duplicate (be safe).
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
        $lines = [];

        if ($this->isInsideProject($projectRoot, $vendorDirAbs)) {
            $lines[] = '';
            $lines[] = '# Composer vendor directory';
            $lines[] = '/' . $this->relativeToProject($projectRoot, $vendorDirAbs) . '/';
        }

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
            'dropins'    => 'Composer-managed WordPress drop-ins',
            'languages'  => 'Composer-managed WordPress language packs',
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
        $path    = $projectRoot . '/.gitignore';
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
        $path = $projectRoot . '/.gitignore';

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

        if (preg_match($pattern, $existing) === 1) {
            // A callback, not a replacement string, so nothing in the block
            // can be read as a "$1" / "\1" back-reference.
            return (string) preg_replace_callback($pattern, static fn (): string => $newBlock, $existing, 1);
        }

        // No existing block: append after exactly one blank line, keeping
        // the line ending the file's last line already used.
        $content = (string) preg_replace('/(?:\r?\n)+\z/', '', $existing);

        if (trim($content) === '') {
            return $newBlock . "\n";
        }

        $eol = preg_match('/\r\n\z/', $existing) === 1 ? "\r\n" : "\n";

        return $content . $eol . "\n" . $newBlock . "\n";
    }

    /**
     * Remove a block together with the blank-line separators around it, so
     * the content either side is left one blank line apart (or the file
     * simply ends where the block began).
     */
    private function stripBlock(string $content, string $blockId): string
    {
        if (preg_match($this->blockPattern($blockId), $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $content;
        }

        [$block, $offset] = $match[0];

        $before = (string) preg_replace('/(?:\r?\n)+\z/', '', substr($content, 0, $offset));
        $after  = (string) preg_replace('/\A(?:\r?\n)+/', '', substr($content, $offset + strlen($block)));

        if (trim($before) === '') {
            return $after;
        }

        if (trim($after) === '') {
            return $before . "\n";
        }

        return $before . "\n\n" . $after;
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
     * True when $absPath is the project root or lies beneath it.
     */
    private function isInsideProject(string $projectRoot, string $absPath): bool
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $absPath     = rtrim(str_replace('\\', '/', $absPath), '/');

        return $absPath === $projectRoot || str_starts_with($absPath, $projectRoot . '/');
    }

    /**
     * Join two relative path segments, tolerating an empty leading segment.
     */
    private function joinRelative(string $base, string $child): string
    {
        return $base === '' ? $child : $base . '/' . $child;
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
