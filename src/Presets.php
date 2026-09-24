<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

/**
 * Host presets: named bundles of default settings.
 *
 *   "extra": { "wp-core-installer": { "preset": "pantheon" } }
 *
 * A preset only fills in settings the project has not set itself:
 *   - scalar settings (e.g. manage-gitignore) — explicit values win;
 *   - list settings (protected-paths, skip-if-exists) — merged, so adding
 *     your own entries never drops the preset's;
 *   - extra.wordpress-install-dir — used only when the project omits it.
 *
 * Keep presets to settings that are true for (nearly) every site on that
 * host; anything project-specific belongs in the project's composer.json.
 */
final class Presets
{
    /** Settings whose values are merged rather than replaced. */
    public const LIST_SETTINGS = ['protected-paths', 'skip-if-exists'];

    /**
     * @var array<string, array{description: string, install-dir?: string, settings: array<string, mixed>}>
     */
    private const PRESETS = [
        'pantheon' => [
            'description' => 'Pantheon with a web/ docroot (web_docroot: true in pantheon.yml) and build-artifact'
                . ' deploys (terminus build:env:push), which commit core and plugins to the Pantheon repo.',
            'install-dir' => 'web',
            'settings'    => [
                'manage-gitignore' => false,
            ],
        ],
        'wpengine' => [
            'description' => 'WP Engine, where the git repository root is the WordPress root.',
            'install-dir' => '.',
            'settings'    => [],
        ],
    ];

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * @return array{description: string, install-dir?: string, settings: array<string, mixed>}
     */
    public static function get(string $name): array
    {
        if (!isset(self::PRESETS[$name])) {
            throw new \UnexpectedValueException(sprintf(
                'WP Core Installer: unknown preset "%s" in extra.wp-core-installer.preset. Available presets: %s.',
                $name,
                implode(', ', self::names())
            ));
        }

        return self::PRESETS[$name];
    }

    /**
     * Apply a preset's defaults underneath the project's own settings.
     *
     * @param array<mixed>         $config  The project's extra.wp-core-installer.
     * @param array<string, mixed> $defaults
     * @return array<mixed>
     */
    public static function merge(array $config, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $value;
            } elseif (in_array($key, self::LIST_SETTINGS, true) && is_array($config[$key]) && is_array($value)) {
                $config[$key] = array_values(array_unique(array_merge($value, $config[$key]), SORT_REGULAR));
            }
        }

        return $config;
    }
}
