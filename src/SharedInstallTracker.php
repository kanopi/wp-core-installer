<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\Package\PackageInterface;

/**
 * Works out which files belong to a package that is installed straight into
 * a shared folder (e.g. the Kinsta MU plugin into wp-content/mu-plugins/),
 * so only those files are gitignored — not the whole folder (#46).
 *
 * Composer doesn't record the files a package extracts, so the folder's
 * top-level entries are listed just before and just after each install or
 * update of such a package; whatever appeared is the package's. The result
 * is saved to <vendor-dir>/.wordpress-core-staging/shared-installs.json and
 * dropped when the package is uninstalled.
 *
 * With no usable record (installed before this version, or over files that
 * were already there), the common bundle
 * convention is assumed: "<name>.php" plus a "<name>/" folder, where <name>
 * is the part of the package name after the slash — if those exist.
 */
class SharedInstallTracker
{
    /** @var array<string, array{dir: string, before: string[]}> Package name => pending snapshot. */
    private array $snapshots = [];

    private ProjectPaths $paths;

    public function __construct(private Composer $composer)
    {
        $this->paths = new ProjectPaths($composer);
    }

    /**
     * pre-package-install / pre-package-update.
     */
    public function beforeInstall(PackageEvent $event): void
    {
        $package = $this->eventPackage($event);
        $dir     = $package === null ? null : $this->sharedInstallDir($package);

        if ($package !== null && $dir !== null) {
            $this->snapshots[$package->getName()] = ['dir' => $dir, 'before' => $this->topLevel($dir)];
        }
    }

    /**
     * post-package-install / post-package-update.
     */
    public function afterInstall(PackageEvent $event): void
    {
        $package = $this->eventPackage($event);

        if ($package === null || !isset($this->snapshots[$package->getName()])) {
            return;
        }

        ['dir' => $dir, 'before' => $before] = $this->snapshots[$package->getName()];
        unset($this->snapshots[$package->getName()]);

        $after   = $this->topLevel($dir);
        $records = $this->load();

        // New entries, plus anything recorded earlier that is still there (an
        // update over existing files doesn't make them "appear").
        $previous = $records[$package->getName()]['entries'] ?? [];
        $entries  = array_values(array_unique(array_merge(
            array_diff($after, $before),
            array_intersect($previous, $after)
        )));

        // Never claim the vendor dir if it lives in the same folder.
        $entries = array_values(array_diff($entries, [$this->vendorEntryIn($dir)]));
        sort($entries);

        $records[$package->getName()] = ['dir' => $dir, 'entries' => $entries];
        $this->save($records);
    }

    /**
     * post-package-uninstall.
     */
    public function afterUninstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        if (!$operation instanceof UninstallOperation) {
            return;
        }

        $records = $this->load();
        unset($records[$operation->getPackage()->getName()]);
        $this->save($records);
    }

    /**
     * The package's own top-level entries in $dir (names only), from the
     * record or, failing that, the "<name>.php" + "<name>/" convention.
     * Only entries that exist are returned.
     *
     * @return array<string, bool> Entry name => whether it is a directory.
     */
    public function entriesFor(PackageInterface $package, string $dir): array
    {
        $record = $this->load()[$package->getName()] ?? null;

        // An empty record (installed over files that were already there)
        // tells us nothing, so it falls back to the convention too.
        if ($record !== null && $record['dir'] === $dir && $record['entries'] !== []) {
            $names = $record['entries'];
        } else {
            $short = substr($package->getName(), (int) strrpos($package->getName(), '/') + 1);
            $names = [$short, $short . '.php'];
        }

        $entries = [];
        foreach ($names as $name) {
            if (file_exists($dir . '/' . $name)) {
                $entries[$name] = is_dir($dir . '/' . $name);
            }
        }

        ksort($entries);

        return $entries;
    }

    private function eventPackage(PackageEvent $event): ?PackageInterface
    {
        $operation = $event->getOperation();

        if ($operation instanceof InstallOperation) {
            return $operation->getPackage();
        }

        if ($operation instanceof UpdateOperation) {
            return $operation->getTargetPackage();
        }

        return null;
    }

    /**
     * The shared folder $package installs straight into, or null.
     */
    private function sharedInstallDir(PackageInterface $package): ?string
    {
        $path = (string) $this->composer->getInstallationManager()->getInstallPath($package);
        $path = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');

        foreach ($this->paths->sharedDirs() as $shared) {
            if ($path === $shared) {
                return $shared;
            }
        }

        return null;
    }

    /**
     * @return string[] Top-level entry names in $dir (dotfiles included).
     */
    private function topLevel(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $names = scandir($dir);

        return $names === false ? [] : array_values(array_diff($names, ['.', '..']));
    }

    private function vendorEntryIn(string $dir): string
    {
        $vendor = $this->paths->vendorDir();

        return dirname($vendor) === $dir ? basename($vendor) : '';
    }

    private function recordPath(): string
    {
        return $this->paths->vendorDir() . '/.wordpress-core-staging/shared-installs.json';
    }

    /**
     * @return array<string, array{dir: string, entries: string[]}>
     */
    private function load(): array
    {
        $path = $this->recordPath();

        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            return [];
        }

        $records = [];
        foreach ($data as $name => $record) {
            if (
                is_string($name)
                && is_array($record)
                && is_string($record['dir'] ?? null)
                && is_array($record['entries'] ?? null)
            ) {
                $records[$name] = [
                    'dir'     => $record['dir'],
                    'entries' => array_values(array_filter($record['entries'], 'is_string')),
                ];
            }
        }

        return $records;
    }

    /**
     * @param array<string, array{dir: string, entries: string[]}> $records
     */
    private function save(array $records): void
    {
        $path = $this->recordPath();

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }

        ksort($records);
        file_put_contents($path, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
