<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

/**
 * Outcome of ChecksumVerifier::verify(). Paths are web-root-relative.
 */
class VerifyResult
{
    /** Number of files whose checksum matched. */
    public int $verified = 0;

    /** @var string[] Files whose content differs from the release. */
    public array $modified = [];

    /** @var string[] Release files that are not in the web-root. */
    public array $missing = [];

    /** @var string[] Files in wp-admin/ or wp-includes/ that the release does not ship. */
    public array $unexpected = [];

    /**
     * Modified or missing files fail verification; unexpected files are
     * reported as warnings only (as `wp core verify-checksums` does).
     */
    public function passed(): bool
    {
        return $this->modified === [] && $this->missing === [];
    }
}
