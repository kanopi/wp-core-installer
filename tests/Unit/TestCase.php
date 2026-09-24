<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\Package\RootPackage;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Gives each test a real (symlink-resolved) temp directory as the working
 * directory, since ProjectPaths derives the project root from getcwd().
 */
abstract class TestCase extends BaseTestCase
{
    protected string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/wpci-unit-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $real = realpath($dir);
        self::assertIsString($real);

        // Forward slashes, as ProjectPaths returns them (realpath() yields
        // backslashes on Windows).
        $this->root        = str_replace('\\', '/', $real);
        $this->previousCwd = (string) getcwd();
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        (new Filesystem())->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * A Composer instance with the given root-package extra and config.
     *
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $config
     */
    protected function composer(array $extra = [], array $config = []): Composer
    {
        $package = new RootPackage('test/project', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);

        $composerConfig = new Config(false, $this->root);
        $composerConfig->merge(['config' => $config]);

        $composer = new Composer();
        $composer->setPackage($package);
        $composer->setConfig($composerConfig);

        return $composer;
    }

    protected function write(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        (new Filesystem())->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);
    }

    protected function read(string $relativePath): string
    {
        return (string) file_get_contents($this->root . '/' . $relativePath);
    }
}
