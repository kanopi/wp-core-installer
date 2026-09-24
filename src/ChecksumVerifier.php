<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

/**
 * Compares deployed core files with the MD5 checksums WordPress.org
 * publishes for each release (the same data `wp core verify-checksums` uses).
 *
 * Fetching is left to the caller so this class stays network-free; see
 * VerifyCommand for the HTTP and --checksums-file sources.
 */
class ChecksumVerifier
{
    /** Directories scanned for files that are not part of the release. */
    private const SCANNED_DIRS = ['wp-admin', 'wp-includes'];

    public static function url(string $version, string $locale): string
    {
        return sprintf(
            'https://api.wordpress.org/core/checksums/1.0/?version=%s&locale=%s',
            rawurlencode($version),
            rawurlencode($locale)
        );
    }

    /**
     * Decode a checksums API response ({"checksums": {"path": "md5", ...}}).
     *
     * @param string $origin Where the JSON came from, for error messages.
     * @return array<string, string> Web-root-relative path => MD5.
     */
    public static function parse(string $json, string $origin): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('WP Core Installer: %s is not valid JSON.', $origin), 0, $e);
        }

        $checksums = is_array($data) ? ($data['checksums'] ?? null) : null;

        if (!is_array($checksums) || $checksums === []) {
            throw new \RuntimeException(
                sprintf('WP Core Installer: %s has no checksums for this version and locale.', $origin)
            );
        }

        $result = [];

        foreach ($checksums as $path => $md5) {
            if (is_string($path) && is_string($md5)) {
                $result[str_replace('\\', '/', $path)] = strtolower($md5);
            }
        }

        return $result;
    }

    /**
     * @param array<string, string>  $checksums Web-root-relative path => MD5.
     * @param callable(string): bool $isChecked Whether a path is ours to verify
     *                                          (protected and skip-if-exists paths
     *                                          belong to the project and are skipped).
     */
    public function verify(string $webRoot, array $checksums, callable $isChecked): VerifyResult
    {
        $result = new VerifyResult();

        foreach ($checksums as $path => $md5) {
            if (!$isChecked($path)) {
                continue;
            }

            $absolute = $webRoot . '/' . $path;

            if (!is_file($absolute)) {
                $result->missing[] = $path;
            } elseif (md5_file($absolute) !== $md5) {
                $result->modified[] = $path;
            } else {
                $result->verified++;
            }
        }

        foreach (self::SCANNED_DIRS as $dir) {
            if (!is_dir($webRoot . '/' . $dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $webRoot . '/' . $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($webRoot) + 1);

                if ($file->isFile() && !isset($checksums[$relative]) && $isChecked($relative)) {
                    $result->unexpected[] = $relative;
                }
            }
        }

        sort($result->missing);
        sort($result->modified);
        sort($result->unexpected);

        return $result;
    }
}
