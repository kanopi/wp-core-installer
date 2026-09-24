<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress\Command;

use Kanopi\Composer\WordPress\ChecksumVerifier;
use Kanopi\Composer\WordPress\ProjectPaths;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * composer wp-core:verify [--locale=en_US] [--checksums-file=PATH]
 */
class VerifyCommand extends AbstractCoreCommand
{
    protected function configure(): void
    {
        $this
            ->setName('wp-core:verify')
            ->setDescription('Verify deployed core files against the checksums published by WordPress.org.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale of the installed release.', 'en_US')
            ->addOption(
                'checksums-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Read checksums from this JSON file (WordPress.org API format) instead of downloading them.'
            )
            ->setHelp(
                "Like `wp core verify-checksums`, without needing WP-CLI or a database.\n\n"
                . "Modified or missing core files fail the check (exit code 1). Files in\n"
                . "wp-admin/ or wp-includes/ that the release does not ship are reported\n"
                . "as warnings. Protected and skip-if-exists paths are not checked."
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

        [$package] = $core;
        $version   = ltrim($package->getPrettyVersion(), 'vV');

        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            $io->writeError(sprintf(
                '<error>%s %s is not a WordPress release version; nothing to verify against.</error>',
                $package->getPrettyName(),
                $package->getPrettyVersion()
            ));
            return 1;
        }

        $locale = $input->getOption('locale');
        $file   = $input->getOption('checksums-file');

        if (!is_string($locale) || preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]+)*$/', $locale) !== 1) {
            $io->writeError('<error>--locale must look like en_US or de_DE.</error>');
            return 1;
        }

        try {
            if (is_string($file)) {
                $json   = is_file($file) ? (string) file_get_contents($file) : '';
                $origin = $file;
                if ($json === '') {
                    throw new \RuntimeException(sprintf('WP Core Installer: cannot read %s.', $file));
                }
            } else {
                $origin = ChecksumVerifier::url($version, $locale);
                $json   = (string) $this->composer()->getLoop()->getHttpDownloader()->get($origin)->getBody();
            }

            $checksums = ChecksumVerifier::parse($json, $origin);
        } catch (\RuntimeException $e) {
            $io->writeError('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $webRoot = (new ProjectPaths($this->composer()))->webRoot();
        $io->write(sprintf('Verifying WordPress %s (%s) in <comment>%s</comment>…', $version, $locale, $webRoot));

        $result = (new ChecksumVerifier())->verify(
            $webRoot,
            $checksums,
            static fn (string $path): bool => $installer->isAlwaysSynced($path)
        );

        foreach ($result->modified as $path) {
            $io->write(sprintf('  <error>modified</error>   %s', $path));
        }
        foreach ($result->missing as $path) {
            $io->write(sprintf('  <error>missing</error>    %s', $path));
        }
        foreach ($result->unexpected as $path) {
            $io->write(sprintf('  <comment>unexpected</comment> %s', $path));
        }

        if (!$result->passed()) {
            $io->writeError(sprintf(
                '<error>Checksum verification failed:</error> %d modified, %d missing (%d verified).',
                count($result->modified),
                count($result->missing),
                $result->verified
            ));
            return 1;
        }

        $io->write(sprintf(
            '<info>Success:</info> %d core files match the WordPress.org checksums%s.',
            $result->verified,
            $result->unexpected === [] ? '' : sprintf(' (%d unexpected file(s))', count($result->unexpected))
        ));

        return 0;
    }
}
