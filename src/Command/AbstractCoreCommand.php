<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Package\PackageInterface;
use Kanopi\Composer\WordPress\CoreInstaller;
use Kanopi\Composer\WordPress\DeployPlan;

/**
 * Shared plumbing for the wp-core:* commands.
 */
abstract class AbstractCoreCommand extends BaseCommand
{
    protected function composer(): Composer
    {
        // getComposer() exists on every Composer 2.x (requireComposer() only
        // from 2.3), and composer-plugin-api ^2.0 includes the 2.2 LTS.
        $composer = $this->getComposer(true);

        if ($composer === null) {
            throw new \RuntimeException('WP Core Installer: this command must run inside a Composer project.');
        }

        return $composer;
    }

    protected function installer(): CoreInstaller
    {
        return new CoreInstaller($this->getIO(), $this->composer());
    }

    /**
     * The installed core package and the directory holding its files, or
     * null (after printing why) when there is nothing to work with.
     *
     * @return array{PackageInterface, string}|null
     */
    protected function locateCore(CoreInstaller $installer): ?array
    {
        $package = $installer->findWordPressCorePackage();

        if ($package === null) {
            $this->getIO()->writeError('<error>No wordpress-core package is installed.</error>');
            return null;
        }

        $source = $installer->locateCoreSource($package);

        if ($source === null) {
            $this->getIO()->writeError(sprintf(
                '<error>%s is not extracted yet; run composer install first.</error>',
                $package->getPrettyName()
            ));
            return null;
        }

        return [$package, $source];
    }

    /**
     * Print a plan: counts always, file lists with -v.
     */
    protected function describePlan(DeployPlan $plan): void
    {
        $io   = $this->getIO();
        $rows = [
            ['Create', array_keys($plan->create)],
            ['Update', array_keys($plan->update)],
            ['First install', array_keys($plan->firstInstall)],
            ['Delete (stale)', $plan->stale],
            ['Unchanged', $plan->unchanged],
            ['Kept (skip-if-exists)', $plan->keptExisting],
            ['Protected', $plan->protected],
        ];

        foreach ($rows as [$label, $paths]) {
            $io->write(sprintf('  %-22s %d', $label . ':', count($paths)));

            // Listing unchanged/protected files is noise even at -v.
            if (!in_array($label, ['Unchanged', 'Kept (skip-if-exists)', 'Protected'], true) || $io->isVeryVerbose()) {
                foreach ($paths as $path) {
                    $io->write('      ' . $path, true, \Composer\IO\IOInterface::VERBOSE);
                }
            }
        }
    }
}
