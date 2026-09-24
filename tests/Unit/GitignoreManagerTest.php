<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Composer\IO\NullIO;
use Kanopi\Composer\WordPress\GitignoreManager;

final class GitignoreManagerTest extends TestCase
{
    /**
     * @param array<mixed> $pluginConfig
     */
    private function manager(array $pluginConfig = []): GitignoreManager
    {
        return new GitignoreManager(new NullIO(), $pluginConfig);
    }

    /**
     * @param string[] $files
     */
    private function writeCore(GitignoreManager $manager, array $files, string $webDir = 'web'): void
    {
        $manager->updateCoreBlock($this->root, $this->root . '/' . $webDir, $files, $this->root . '/vendor');
    }

    private function block(string $blockId): string
    {
        $matched = preg_match(
            sprintf('/# <kanopi\/wp-core-installer:%1$s:begin>.*?# <kanopi\/wp-core-installer:%1$s:end>/s', $blockId),
            $this->read('.gitignore'),
            $match
        );

        return $matched === 1 ? $match[0] : '';
    }

    /**
     * @return string[] Non-comment, non-blank lines of the block.
     */
    private function entries(string $blockId): array
    {
        return array_values(array_filter(
            explode("\n", $this->block($blockId)),
            static fn (string $line): bool => $line !== '' && $line[0] !== '#'
        ));
    }

    public function testCoreBlockCollapsesTopLevelEntriesAndListsWpContentFiles(): void
    {
        $this->writeCore($this->manager(), [
            'wp-admin/index.php',
            'wp-admin/css/a.css',
            'wp-includes/version.php',
            'wp-load.php',
            'index.php',
            'wp-content/index.php',
        ]);

        self::assertSame([
            '/vendor/.wordpress-core-staging/',
            '/web/index.php',
            '/web/wp-admin/',
            '/web/wp-content/index.php',
            '/web/wp-includes/',
            '/web/wp-load.php',
        ], $this->entries('core'));
    }

    public function testCoreBlockWithProjectRootAsWebRoot(): void
    {
        $this->writeCore($this->manager(), ['wp-admin/index.php', 'wp-load.php'], '');

        self::assertContains('/wp-admin/', $this->entries('core'));
        self::assertContains('/wp-load.php', $this->entries('core'));
    }

    public function testBlocksAreAppendedAfterUserContentAndReplacedInPlace(): void
    {
        $this->write('.gitignore', "/node_modules/\n.env\n");
        $manager = $this->manager();

        $this->writeCore($manager, ['wp-load.php']);
        $this->writeCore($manager, ['wp-settings.php']);

        $contents = $this->read('.gitignore');
        self::assertStringStartsWith("/node_modules/\n.env\n", $contents);
        self::assertSame(1, substr_count($contents, 'core:begin'));
        self::assertStringContainsString('/web/wp-settings.php', $contents);
        self::assertStringNotContainsString('/web/wp-load.php', $contents);
    }

    public function testUserContentAfterABlockSurvivesAReplace(): void
    {
        $manager = $this->manager();
        $this->writeCore($manager, ['wp-load.php']);
        file_put_contents($this->root . '/.gitignore', "/after-block\n", FILE_APPEND);

        $this->writeCore($manager, ['wp-settings.php']);

        self::assertStringEndsWith("/after-block\n", $this->read('.gitignore'));
    }

    public function testCrlfUserContentIsPreserved(): void
    {
        $this->write('.gitignore', "/node_modules/\r\n.env\r\n");

        $this->writeCore($this->manager(), ['wp-load.php']);

        self::assertStringStartsWith("/node_modules/\r\n.env\r\n", $this->read('.gitignore'));
    }

    public function testRemovingTheOnlyBlockDeletesTheFile(): void
    {
        $manager = $this->manager();
        $this->writeCore($manager, ['wp-load.php']);

        $manager->removeCoreBlock($this->root);

        self::assertFileDoesNotExist($this->root . '/.gitignore');
    }

    public function testRemovingABlockKeepsOtherContent(): void
    {
        $this->write('.gitignore', "/node_modules/\n");
        $manager = $this->manager();
        $this->writeCore($manager, ['wp-load.php']);
        $manager->updatePackagesBlock($this->root, $this->root . '/vendor', []);

        $manager->removeCoreBlock($this->root);

        $contents = $this->read('.gitignore');
        self::assertStringContainsString('/node_modules/', $contents);
        self::assertStringNotContainsString('core:begin', $contents);
        self::assertStringContainsString('packages:begin', $contents);
    }

    public function testPackagesBlockSectionsAndMuPluginFile(): void
    {
        $this->manager()->updatePackagesBlock(
            $this->root,
            $this->root . '/vendor',
            [
                'plugins'   => ['web/wp-content/plugins/b', 'web/wp-content/plugins/a/'],
                'dropins'   => ['web/wp-content/object-cache'],
                'languages' => ['web/wp-content/languages/de_DE'],
            ],
            $this->root . '/web/wp-content/mu-plugins/000-autoloader.php'
        );

        self::assertSame([
            '/vendor/',
            '/web/wp-content/mu-plugins/000-autoloader.php',
            '/web/wp-content/plugins/a/',
            '/web/wp-content/plugins/b/',
            '/web/wp-content/object-cache/',
            '/web/wp-content/languages/de_DE/',
        ], $this->entries('packages'));
        self::assertStringContainsString('# Composer-managed WordPress drop-ins', $this->block('packages'));
        self::assertStringContainsString('# Composer-managed WordPress language packs', $this->block('packages'));
    }

    public function testPathsOutsideTheProjectAreNeverWritten(): void
    {
        $outside = dirname($this->root) . '/elsewhere';
        $manager = $this->manager();

        $manager->updateCoreBlock($this->root, $outside . '/web', ['wp-load.php'], $outside . '/vendor');
        $manager->updatePackagesBlock($this->root, $outside . '/vendor', [], $outside . '/mu/000-autoloader.php');

        self::assertSame([], $this->entries('core'));
        self::assertSame([], $this->entries('packages'));
        self::assertStringNotContainsString('elsewhere', $this->read('.gitignore'));
    }

    /**
     * @return array<string, array{mixed, bool, bool}>
     */
    public static function manageGitignoreProvider(): array
    {
        return [
            'default'          => [null, true, true],
            'true'             => [true, true, true],
            'false'            => [false, false, false],
            'core off'         => [['core' => false], false, true],
            'packages off'     => [['packages' => false], true, false],
            'unrelated key'    => [['other' => false], true, true],
        ];
    }

    /**
     * @dataProvider manageGitignoreProvider
     */
    public function testIsBlockManaged(mixed $setting, bool $core, bool $packages): void
    {
        $manager = $this->manager($setting === null ? [] : ['manage-gitignore' => $setting]);

        self::assertSame($core, $manager->isBlockManaged('core'));
        self::assertSame($packages, $manager->isBlockManaged('packages'));
    }

    public function testAnUnmanagedBlockIsRemovedAndNotRewritten(): void
    {
        $this->writeCore($this->manager(), ['wp-load.php']);

        $this->writeCore($this->manager(['manage-gitignore' => false]), ['wp-load.php']);

        self::assertFileDoesNotExist($this->root . '/.gitignore');
    }

    public function testRelativeToProject(): void
    {
        $manager = $this->manager();

        self::assertSame('', $manager->relativeToProject('/srv/app', '/srv/app'));
        self::assertSame('web/wp', $manager->relativeToProject('/srv/app/', '/srv/app/web/wp/'));
        self::assertSame('/srv/application', $manager->relativeToProject('/srv/app', '/srv/application'));
    }
}
