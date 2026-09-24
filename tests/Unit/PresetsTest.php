<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Kanopi\Composer\WordPress\Presets;
use Kanopi\Composer\WordPress\ProjectPaths;

final class PresetsTest extends TestCase
{
    public function testMergeFillsMissingSettingsOnly(): void
    {
        self::assertSame(
            ['manage-gitignore' => true, 'mu-plugins-dir' => 'app/mu'],
            Presets::merge(['manage-gitignore' => true], ['manage-gitignore' => false, 'mu-plugins-dir' => 'app/mu'])
        );
    }

    public function testMergeCombinesListSettings(): void
    {
        self::assertSame(
            ['protected-paths' => ['private', 'config']],
            Presets::merge(['protected-paths' => ['config', 'private']], ['protected-paths' => ['private']])
        );
    }

    public function testUnknownPresetListsTheAvailableOnes(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown preset "kinsta"');
        $this->expectExceptionMessage('Available presets: pantheon, wpengine');

        Presets::get('kinsta');
    }

    public function testPantheonPreset(): void
    {
        $paths = new ProjectPaths($this->composer(['wp-core-installer' => ['preset' => 'pantheon']]));

        self::assertSame($this->root . '/web', $paths->webRoot());
        self::assertFalse($paths->pluginConfig()['manage-gitignore']);
    }

    public function testWpEnginePreset(): void
    {
        $paths = new ProjectPaths($this->composer(['wp-core-installer' => ['preset' => 'wpengine']]));

        self::assertSame($this->root, $paths->webRoot());
    }

    public function testExplicitSettingsBeatThePreset(): void
    {
        $paths = new ProjectPaths($this->composer([
            'wordpress-install-dir' => 'public',
            'wp-core-installer'     => ['preset' => 'pantheon', 'manage-gitignore' => true],
        ]));

        self::assertSame($this->root . '/public', $paths->webRoot());
        self::assertTrue($paths->pluginConfig()['manage-gitignore']);
    }

    public function testNoPresetLeavesConfigUntouched(): void
    {
        $paths = new ProjectPaths($this->composer(['wp-core-installer' => ['skip-if-exists' => ['robots.txt']]]));

        self::assertNull($paths->preset());
        self::assertSame(['skip-if-exists' => ['robots.txt']], $paths->pluginConfig());
    }

    public function testEveryPresetHasADescription(): void
    {
        foreach (Presets::names() as $name) {
            self::assertNotSame('', Presets::get($name)['description'], $name);
        }
    }
}
