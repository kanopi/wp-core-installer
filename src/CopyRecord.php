<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

/**
 * What one copy-to entry did last time it ran, stored as JSON under
 * <vendor-dir>/.wordpress-core-staging/copy-to/. PackageCopier uses it to
 * update, clean up after, or undo an entry without touching anything else.
 *
 * $files are paths relative to $dir: every file placed (overwrite,
 * if-missing), or the one file holding the managed section (append,
 * prepend).
 */
class CopyRecord
{
    private const FORMAT = 1;

    /**
     * @param string[] $files
     */
    public function __construct(
        public string $key,
        public string $dir,
        public string $mode,
        public bool $gitignore,
        public string $comment,
        public array $files = []
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

        if (
            !is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_string($data['key'] ?? null)
            || !is_string($data['dir'] ?? null)
            || !is_string($data['mode'] ?? null)
            || !is_bool($data['gitignore'] ?? null)
            || !is_string($data['comment'] ?? null)
            || !is_array($data['files'] ?? null)
        ) {
            return null;
        }

        return new self(
            $data['key'],
            $data['dir'],
            $data['mode'],
            $data['gitignore'],
            $data['comment'],
            array_values(array_filter($data['files'], 'is_string'))
        );
    }

    public function save(string $path): bool
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
            return false;
        }

        $json = json_encode(
            [
                'format'    => self::FORMAT,
                'key'       => $this->key,
                'dir'       => $this->dir,
                'mode'      => $this->mode,
                'gitignore' => $this->gitignore,
                'comment'   => $this->comment,
                'files'     => array_values($this->files),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        return $json !== false && file_put_contents($path, $json . "\n") !== false;
    }
}
