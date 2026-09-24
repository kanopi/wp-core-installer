<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * composer wp-core:status
 *
 * Exits 0 when the web-root matches the installed core package and 1 when
 * it has drifted (or core is missing), so it can gate CI.
 */
class StatusCommand extends AbstractCoreCommand
{
    protected function configure(): void
    {
        $this
            ->setName('wp-core:status')
            ->setDescription('Show whether the web-root matches the installed WordPress core package.')
            ->setHelp(
                "Compares every always-synced core file in the web-root with the installed\n"
                . "package and reports files that are missing, changed or stale.\n\n"
                . "Exit code 0 means in sync; 1 means drift (run composer wp-core:deploy).\n"
                . "Use -v to list the affected files."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = $this->getIO();
        $installer = $this->installer();
        $core      = $this->locateCore($installer);

        if ($core === null) {
            return 1;
        }

        [$package, $source] = $core;
        $plan     = $installer->planDeployment($package, $source);
        $manifest = $installer->previousManifest();

        $io->write(sprintf('  %-22s %s %s', 'Package:', $package->getPrettyName(), $package->getPrettyVersion()));
        $io->write(sprintf('  %-22s %s', 'Web-root:', $plan->webRoot));
        $io->write(sprintf(
            '  %-22s %s',
            'Last deploy:',
            $manifest === null
                ? 'none recorded (no deploy manifest yet)'
                : sprintf('%s, %d files', $manifest->version, count($manifest->files))
        ));

        if (!$plan->hasChanges()) {
            $io->write(sprintf(
                '<info>In sync:</info> %d core files match %s.',
                count($plan->unchanged),
                $package->getPrettyName()
            ));

            return 0;
        }

        $io->write(sprintf('<comment>Out of sync:</comment> %s.', $plan->summary()));
        $this->describePlan($plan);
        $io->write('Run <info>composer wp-core:deploy</info> to bring the web-root in line.');

        return 1;
    }
}
