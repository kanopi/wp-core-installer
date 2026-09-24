<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Composer\IO\NullIO;
use Kanopi\Composer\WordPress\MuPluginScaffolder;

final class MuPluginScaffolderTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function relativePathProvider(): array
    {
        return [
            'vendor inside mu-plugins' => [
                '/srv/app/web/wp-content/mu-plugins',
                '/srv/app/web/wp-content/mu-plugins/vendor/autoload.php',
                'vendor/autoload.php',
            ],
            'vendor at project root, web-root = project root' => [
                '/srv/app/wp-content/mu-plugins',
                '/srv/app/vendor/autoload.php',
                '../../vendor/autoload.php',
            ],
            'vendor at project root, web-root = public' => [
                '/srv/app/public/wp-content/mu-plugins',
                '/srv/app/vendor/autoload.php',
                '../../../vendor/autoload.php',
            ],
            'trailing slash on the mu-plugins dir' => [
                '/srv/app/wp-content/mu-plugins/',
                '/srv/app/vendor/autoload.php',
                '../../vendor/autoload.php',
            ],
            'windows separators' => [
                'C:\\app\\wp-content\\mu-plugins',
                'C:\\app\\vendor\\autoload.php',
                '../../vendor/autoload.php',
            ],
        ];
    }

    /**
     * @dataProvider relativePathProvider
     */
    public function testComputeRelativePath(string $from, string $to, string $expected): void
    {
        $scaffolder = new MuPluginScaffolder($this->composer(), new NullIO());
        $method     = new \ReflectionMethod($scaffolder, 'computeRelativePath');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($scaffolder, $from, $to));
    }
}
