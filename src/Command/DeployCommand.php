<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * composer wp-core:deploy [--force] [--dry-run]
 */
class DeployCommand extends AbstractCoreCommand
{
    protected function configure(): void
    {
        $this
            ->setName('wp-core:deploy')
            ->setDescription('Deploy WordPress core from the staging directory into the web-root.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Rewrite every core file, even unchanged ones.')
            ->setHelp(
                "Copies new and changed core files into the web-root, deletes files the\n"
                . "installed release no longer ships, and refreshes the deploy manifest and\n"
                . ".gitignore core block. Protected and skip-if-exists paths are honoured.\n\n"
                . "Use -v with --dry-run to list the affected files."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installer = $this->installer();
        $core      = $this->locateCore($installer);

        if ($core === null) {
            return 1;
        }

        [$package, $source] = $core;
        $plan = $installer->planDeployment($package, $source);

        if ($input->getOption('force')) {
            foreach ($plan->unchanged as $relative) {
                $plan->update[$relative] = $plan->sourceDir . '/' . $relative;
            }
            $plan->unchanged = [];
        }

        if ($input->getOption('dry-run')) {
            $this->getIO()->write(sprintf(
                '<info>Dry run:</info> deploying %s %s to <comment>%s</comment> would change: %s',
                $package->getPrettyName(),
                $package->getPrettyVersion(),
                $plan->webRoot,
                $plan->summary()
            ));
            $this->describePlan($plan);

            return 0;
        }

        $this->getIO()->write(sprintf(
            '<info>WP Core Installer:</info> Deploying %s %s to web-root…',
            $package->getPrettyName(),
            $package->getPrettyVersion()
        ));
        $installer->applyPlan($plan);

        return 0;
    }
}
