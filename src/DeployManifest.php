<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

/**
 * Record of the last WordPress core deployment, stored as JSON next to the
 * staging directory (vendor/.wordpress-core-staging/.deploy-manifest.json).
 *
 * It lets CoreInstaller:
 *   - delete files that a newer core release no longer ships (stale files);
 *   - skip re-copying core when nothing has changed since the last deploy.
 *
 * Only always-synced core files are listed. Skip-if-exists files belong to
 * the project after first install and are never deleted.
 *
 * A missing or unreadable manifest (first run, vendor/ wiped, upgrade from a
 * plugin version without manifests) simply means "no previous deploy known":
 * nothing is deleted and core is deployed in full.
 */
class DeployManifest
{
    private const FORMAT = 1;

    /**
     * @param string[] $files Web-root-relative paths (forward slashes) of deployed files.
     */
    public function __construct(
        public readonly string $package,
        public readonly string $version,
        public readonly string $reference,
        public readonly string $webRoot,
        public readonly string $configHash,
        public readonly array $files = []
    ) {
    }

    public static function load(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            return null;
        }

        foreach (['package', 'version', 'reference', 'webRoot', 'configHash'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                return null;
            }
        }

        if (!is_array($data['files'] ?? null)) {
            return null;
        }

        return new self(
            $data['package'],
            $data['version'],
            $data['reference'],
            $data['webRoot'],
            $data['configHash'],
            array_values(array_filter($data['files'], 'is_string'))
        );
    }

    public function save(string $path): bool
    {
        $json = json_encode(
            [
                'format'     => self::FORMAT,
                'package'    => $this->package,
                'version'    => $this->version,
                'reference'  => $this->reference,
                'webRoot'    => $this->webRoot,
                'configHash' => $this->configHash,
                'files'      => $this->files,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            return false;
        }

        return $json !== false && file_put_contents($path, $json . "\n") !== false;
    }

    /**
     * Same package build, same destination, same protection rules.
     */
    public function describesSameDeployAs(self $other): bool
    {
        return $this->package === $other->package
            && $this->version === $other->version
            && $this->reference === $other->reference
            && $this->webRoot === $other->webRoot
            && $this->configHash === $other->configHash;
    }

    /**
     * Every recorded file is still present in the web-root.
     */
    public function isIntact(): bool
    {
        if ($this->files === []) {
            return false;
        }

        foreach ($this->files as $file) {
            if (!is_file($this->webRoot . '/' . $file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Files this manifest recorded that $next no longer contains.
     *
     * @return string[]
     */
    public function filesRemovedIn(self $next): array
    {
        return array_values(array_diff($this->files, $next->files));
    }

    /**
     * @param string[] $files
     */
    public function withFiles(array $files): self
    {
        return new self(
            $this->package,
            $this->version,
            $this->reference,
            $this->webRoot,
            $this->configHash,
            array_values(array_unique($files))
        );
    }
}
