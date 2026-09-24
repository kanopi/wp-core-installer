<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Kanopi\Composer\WordPress\ChecksumVerifier;

final class ChecksumVerifierTest extends TestCase
{
    public function testUrl(): void
    {
        self::assertSame(
            'https://api.wordpress.org/core/checksums/1.0/?version=6.8.3&locale=de_DE',
            ChecksumVerifier::url('6.8.3', 'de_DE')
        );
    }

    public function testParse(): void
    {
        self::assertSame(
            ['wp-load.php' => 'abc', 'wp-admin/index.php' => 'def'],
            ChecksumVerifier::parse('{"checksums": {"wp-load.php": "ABC", "wp-admin\\\\index.php": "def"}}', 'x')
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badResponseProvider(): array
    {
        return [
            'not json'        => ['<html>', 'is not valid JSON'],
            'unknown version' => ['{"checksums": false}', 'has no checksums'],
            'empty'           => ['{"checksums": {}}', 'has no checksums'],
            'wrong shape'     => ['[1, 2]', 'has no checksums'],
        ];
    }

    /**
     * @dataProvider badResponseProvider
     */
    public function testParseRejectsUnusableResponses(string $json, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        ChecksumVerifier::parse($json, 'api');
    }

    public function testVerifyClassifiesFiles(): void
    {
        $this->write('web/wp-load.php', 'load');
        $this->write('web/wp-admin/index.php', 'changed');
        $this->write('web/wp-includes/extra.php', 'extra');
        $this->write('web/wp-config-sample.php', 'mine');

        $checksums = [
            'wp-load.php'          => md5('load'),
            'wp-admin/index.php'   => md5('original'),
            'wp-includes/gone.php' => md5('gone'),
            'wp-config-sample.php' => md5('sample'),
        ];
        $isChecked = static fn (string $path): bool => $path !== 'wp-config-sample.php';

        $result = (new ChecksumVerifier())->verify($this->root . '/web', $checksums, $isChecked);

        self::assertSame(1, $result->verified);
        self::assertSame(['wp-admin/index.php'], $result->modified);
        self::assertSame(['wp-includes/gone.php'], $result->missing);
        self::assertSame(['wp-includes/extra.php'], $result->unexpected);
        self::assertFalse($result->passed());
    }

    public function testUnexpectedFilesAloneStillPass(): void
    {
        $this->write('web/wp-load.php', 'load');
        $this->write('web/wp-admin/new.php', 'new');

        $isChecked = static fn (string $path): bool => true;

        $result = (new ChecksumVerifier())->verify($this->root . '/web', ['wp-load.php' => md5('load')], $isChecked);

        self::assertTrue($result->passed());
        self::assertSame(['wp-admin/new.php'], $result->unexpected);
    }
}
