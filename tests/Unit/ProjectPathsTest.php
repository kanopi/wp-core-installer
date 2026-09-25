<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Kanopi\Composer\WordPress\ProjectPaths;

final class ProjectPathsTest extends TestCase
{
    public function testDefaults(): void
    {
        $paths = new ProjectPaths($this->composer());

        self::assertSame($this->root, $paths->projectRoot());
        self::assertSame($this->root . '/public', $paths->webRoot());
        self::assertSame($this->root . '/public/wp-content/mu-plugins', $paths->muPluginsDir());
        self::assertSame($this->root . '/vendor', $paths->vendorDir());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function installDirProvider(): array
    {
        return [
            'dot'             => ['.', ''],
            'empty'           => ['', ''],
            'plain'           => ['web', '/web'],
            'nested'          => ['public/wp', '/public/wp'],
            'leading dot'     => ['./public', '/public'],
            'trailing slash'  => ['public/', '/public'],
            'double slash'    => ['public//wp', '/public/wp'],
            'parent segment'  => ['public/../web', '/web'],
            'surrounding ws'  => [' web ', '/web'],
        ];
    }

    /**
     * @dataProvider installDirProvider
     */
    public function testInstallDirIsNormalised(string $configured, string $expectedSuffix): void
    {
        $paths = new ProjectPaths($this->composer(['wordpress-install-dir' => $configured]));

        self::assertSame($this->root . $expectedSuffix, $paths->webRoot());
        self::assertSame($this->root . $expectedSuffix . '/wp-content/mu-plugins', $paths->muPluginsDir());
    }

    public function testAbsoluteInstallDirIsUsedAsIs(): void
    {
        $external = $this->root . '/elsewhere/web';

        $paths = new ProjectPaths($this->composer(['wordpress-install-dir' => $external . '/']));

        self::assertSame($external, $paths->webRoot());
        self::assertSame($external . '/wp-content/mu-plugins', $paths->muPluginsDir());
    }

    public function testMuPluginsDirIsRelativeToTheWebRoot(): void
    {
        $paths = new ProjectPaths($this->composer([
            'wordpress-install-dir' => 'web',
            'wp-core-installer'     => ['mu-plugins-dir' => 'app/mu-plugins'],
        ]));

        self::assertSame($this->root . '/web/app/mu-plugins', $paths->muPluginsDir());
    }

    public function testCustomVendorDir(): void
    {
        $paths = new ProjectPaths($this->composer([], ['vendor-dir' => 'web/wp-content/mu-plugins/vendor']));

        self::assertSame($this->root . '/web/wp-content/mu-plugins/vendor', $paths->vendorDir());
    }

    public function testSymlinkedAncestorsAreCanonicalised(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Creating symlinks on Windows needs elevated privileges.');
        }

        mkdir($this->root . '/real');
        symlink($this->root . '/real', $this->root . '/link');

        $paths = new ProjectPaths($this->composer(['wordpress-install-dir' => $this->root . '/link/not-yet-created']));

        self::assertSame($this->root . '/real/not-yet-created', $paths->webRoot());
    }

    public function testConfigStringList(): void
    {
        $paths = new ProjectPaths($this->composer(['wp-core-installer' => ['protected-paths' => ['a', 'b/c']]]));

        self::assertSame(['a', 'b/c'], $paths->configStringList('protected-paths'));
        self::assertSame([], $paths->configStringList('skip-if-exists'));
    }

    public function testBundledPaths(): void
    {
        $paths = new ProjectPaths($this->composer(['wp-core-installer' => ['deploy-bundled' => [
            'themes'  => ['twentytwentyfive'],
            'plugins' => ['akismet', 'hello.php', 'akismet'],
        ]]]));

        self::assertSame(
            ['wp-content/themes/twentytwentyfive', 'wp-content/plugins/akismet', 'wp-content/plugins/hello.php'],
            $paths->bundledPaths()
        );
        self::assertSame([], (new ProjectPaths($this->composer()))->bundledPaths());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function relativePathProvider(): array
    {
        return [
            'sibling file'      => ['/app/web', '/app/vendor/autoload.php', '../vendor/autoload.php'],
            'parent directory'  => ['/app/web', '/app', '..'],
            'same directory'    => ['/app', '/app', ''],
            'child'             => ['/app', '/app/web/wp-config.php', 'web/wp-config.php'],
            'two levels up'     => ['/app/public/wp', '/app/vendor/autoload.php', '../../vendor/autoload.php'],
            'windows drive'     => ['C:/app/web', 'C:/app/vendor/autoload.php', '../vendor/autoload.php'],
            'trailing slashes'  => ['/app/web/', '/app/', '..'],
        ];
    }

    /**
     * @dataProvider relativePathProvider
     */
    public function testRelativePath(string $from, string $to, string $expected): void
    {
        self::assertSame($expected, ProjectPaths::relativePath($from, $to));
    }

    public function testConfigBool(): void
    {
        $paths = new ProjectPaths($this->composer(['wp-core-installer' => ['scaffold-wp-config' => true]]));

        self::assertTrue($paths->configBool('scaffold-wp-config', false));
        self::assertFalse($paths->configBool('something-else', false));
    }

    public function testCopyTargetsResolveAgainstTheWebRoot(): void
    {
        $paths = new ProjectPaths($this->composer([
            'wordpress-install-dir' => 'public',
            'wp-core-installer'     => ['copy-to' => [
                'Kinsta/Kinsta-MU-Plugins' => 'wp-content/mu-plugins',
                'acme/dropin'              => $this->root . '/elsewhere/',
            ]],
        ]));

        self::assertSame([
            'kinsta/kinsta-mu-plugins' => $this->root . '/public/wp-content/mu-plugins',
            'acme/dropin'              => $this->root . '/elsewhere',
        ], $paths->copyTargets());
        self::assertSame([], (new ProjectPaths($this->composer()))->copyTargets());
    }

    public function testSharedDirs(): void
    {
        $paths = new ProjectPaths($this->composer([
            'wordpress-install-dir' => 'web',
            'wp-core-installer'     => ['mu-plugins-dir' => 'app/mu-plugins'],
        ]));

        self::assertSame([
            $this->root . '/web/wp-content',
            $this->root . '/web/wp-content/plugins',
            $this->root . '/web/wp-content/themes',
            $this->root . '/web/wp-content/mu-plugins',
            $this->root . '/web/app/mu-plugins',
        ], $paths->sharedDirs());
    }

    /**
     * @return array<string, array{array<string, mixed>, callable(ProjectPaths): mixed, string}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'install dir not a string' => [
                ['wordpress-install-dir' => ['web']],
                static fn (ProjectPaths $p): string => $p->webRoot(),
                'extra.wordpress-install-dir in composer.json must be a string, array given',
            ],
            'plugin config not an object' => [
                ['wp-core-installer' => 'yes'],
                static fn (ProjectPaths $p): array => $p->pluginConfig(),
                'extra.wp-core-installer in composer.json must be an object',
            ],
            'list not an array' => [
                ['wp-core-installer' => ['protected-paths' => 'config']],
                static fn (ProjectPaths $p): array => $p->configStringList('protected-paths'),
                'extra.wp-core-installer.protected-paths in composer.json must be an array of strings',
            ],
            'list item not a string' => [
                ['wp-core-installer' => ['skip-if-exists' => ['robots.txt', 1]]],
                static fn (ProjectPaths $p): array => $p->configStringList('skip-if-exists'),
                'extra.wp-core-installer.skip-if-exists[] in composer.json must be a string, int given',
            ],
            'bool setting given a string' => [
                ['wp-core-installer' => ['scaffold-wp-config' => 'yes']],
                static fn (ProjectPaths $p): bool => $p->configBool('scaffold-wp-config', false),
                'scaffold-wp-config in composer.json must be true or false, string given',
            ],
            'deploy-bundled not an object' => [
                ['wp-core-installer' => ['deploy-bundled' => 'akismet']],
                static fn (ProjectPaths $p): array => $p->bundledPaths(),
                'deploy-bundled in composer.json must be an object',
            ],
            'deploy-bundled unknown kind' => [
                ['wp-core-installer' => ['deploy-bundled' => ['mu-plugins' => ['x']]]],
                static fn (ProjectPaths $p): array => $p->bundledPaths(),
                'only accepts "themes" and "plugins", not "mu-plugins"',
            ],
            'deploy-bundled path traversal' => [
                ['wp-core-installer' => ['deploy-bundled' => ['themes' => ['../uploads']]]],
                static fn (ProjectPaths $p): array => $p->bundledPaths(),
                'must be a single theme or plugin name',
            ],
            'deploy-bundled nested path' => [
                ['wp-core-installer' => ['deploy-bundled' => ['plugins' => ['akismet/akismet.php']]]],
                static fn (ProjectPaths $p): array => $p->bundledPaths(),
                'must be a single theme or plugin name',
            ],
            'copy-to not an object' => [
                ['wp-core-installer' => ['copy-to' => 'kinsta/kinsta-mu-plugins']],
                static fn (ProjectPaths $p): array => $p->copyTargets(),
                'copy-to in composer.json must be an object',
            ],
            'copy-to key not a package name' => [
                ['wp-core-installer' => ['copy-to' => ['kinsta' => 'wp-content/mu-plugins']]],
                static fn (ProjectPaths $p): array => $p->copyTargets(),
                'copy-to keys must be package names like "vendor/package", "kinsta" given',
            ],
            'copy-to target not a string' => [
                ['wp-core-installer' => ['copy-to' => ['kinsta/kinsta-mu-plugins' => true]]],
                static fn (ProjectPaths $p): array => $p->copyTargets(),
                'copy-to.kinsta/kinsta-mu-plugins in composer.json must be a string, bool given',
            ],
            'mu-plugins dir not a string' => [
                ['wp-core-installer' => ['mu-plugins-dir' => false]],
                static fn (ProjectPaths $p): string => $p->muPluginsDir(),
                'extra.wp-core-installer.mu-plugins-dir in composer.json must be a string, bool given',
            ],
        ];
    }

    /**
     * @dataProvider invalidConfigProvider
     *
     * @param array<string, mixed>          $extra
     * @param callable(ProjectPaths): mixed $call
     */
    public function testInvalidConfigFailsWithAClearMessage(array $extra, callable $call, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $call(new ProjectPaths($this->composer($extra)));
    }
}
