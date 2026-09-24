<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Tests;

use Kanopi\Composer\WordPress\DeployManifest;

final class DeployManifestTest extends TestCase
{
    /**
     * @param string[] $files
     */
    private function manifest(string $version = '6.8.3', array $files = ['wp-load.php']): DeployManifest
    {
        return new DeployManifest('fake/core', $version, 'abc123', $this->root . '/web', 'hash', $files);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $path     = $this->root . '/staging/.deploy-manifest.json';
        $original = $this->manifest('6.8.3', ['wp-load.php', 'wp-admin/index.php']);

        self::assertTrue($original->save($path));

        $loaded = DeployManifest::load($path);
        self::assertNotNull($loaded);
        self::assertTrue($loaded->describesSameDeployAs($original));
        self::assertSame(['wp-load.php', 'wp-admin/index.php'], $loaded->files);
    }

    public function testLoadReturnsNullForMissingOrUnusableFiles(): void
    {
        self::assertNull(DeployManifest::load($this->root . '/missing.json'));

        $this->write('garbage.json', '{not json');
        self::assertNull(DeployManifest::load($this->root . '/garbage.json'));

        $this->write('future.json', '{"format": 99, "files": []}');
        self::assertNull(DeployManifest::load($this->root . '/future.json'));

        $this->write('partial.json', '{"format": 1, "package": "x", "files": []}');
        self::assertNull(DeployManifest::load($this->root . '/partial.json'));
    }

    public function testNonStringFileEntriesAreDropped(): void
    {
        $this->write('m.json', (string) json_encode([
            'format' => 1, 'package' => 'p', 'version' => 'v', 'reference' => 'r',
            'webRoot' => '/w', 'configHash' => 'h', 'files' => ['a.php', 7, null, 'b.php'],
        ]));

        self::assertSame(['a.php', 'b.php'], DeployManifest::load($this->root . '/m.json')?->files);
    }

    public function testDescribesSameDeployAsComparesEverythingButFiles(): void
    {
        $base = $this->manifest();

        self::assertTrue($base->describesSameDeployAs($base->withFiles(['other.php'])));
        self::assertFalse($base->describesSameDeployAs($this->manifest('6.9.0')));
        self::assertFalse($base->describesSameDeployAs(
            new DeployManifest('fake/core', '6.8.3', 'abc123', $this->root . '/web', 'other-hash')
        ));
        self::assertFalse($base->describesSameDeployAs(
            new DeployManifest('fake/core', '6.8.3', 'abc123', $this->root . '/public', 'hash')
        ));
    }

    public function testIsIntactRequiresEveryRecordedFile(): void
    {
        $manifest = $this->manifest('6.8.3', ['wp-load.php', 'wp-admin/index.php']);
        self::assertFalse($manifest->isIntact());

        $this->write('web/wp-load.php', '<?php');
        self::assertFalse($manifest->isIntact());

        $this->write('web/wp-admin/index.php', '<?php');
        self::assertTrue($manifest->isIntact());
    }

    public function testAnEmptyManifestIsNeverIntact(): void
    {
        self::assertFalse($this->manifest('6.8.3', [])->isIntact());
    }

    public function testFilesRemovedIn(): void
    {
        $old = $this->manifest('6.8.3', ['a.php', 'b.php', 'c.php']);
        $new = $this->manifest('6.9.0', ['a.php', 'c.php', 'd.php']);

        self::assertSame(['b.php'], $old->filesRemovedIn($new));
    }

    public function testWithFilesDeduplicatesAndKeepsIdentity(): void
    {
        $manifest = $this->manifest()->withFiles(['a.php', 'a.php', 'b.php']);

        self::assertSame(['a.php', 'b.php'], $manifest->files);
        self::assertSame('6.8.3', $manifest->version);
    }
}
