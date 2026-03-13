<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin entry point.
 *
 * Responsibilities:
 *   1. Registers CoreInstaller so packages of type "wordpress-core" are
 *      deployed safely to the configured web-root.
 *   2. Subscribes to post-install-cmd / post-update-cmd so that every
 *      Composer-managed plugin and theme (installed by composer/installers)
 *      is tracked in .gitignore after the full dependency tree is resolved.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    // -------------------------------------------------------------------------
    // PluginInterface
    // -------------------------------------------------------------------------

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;

        $composer->getInstallationManager()->addInstaller(
            new CoreInstaller($io, $composer)
        );
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Nothing to tear down.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Nothing to clean up on removal of this plugin itself.
    }

    // -------------------------------------------------------------------------
    // EventSubscriberInterface
    // -------------------------------------------------------------------------

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['onPostInstallOrUpdate', 0],
            ScriptEvents::POST_UPDATE_CMD  => ['onPostInstallOrUpdate', 0],
        ];
    }

    /**
     * After all packages have been installed / updated:
     *   1. Scaffold the Composer autoloader mu-plugin (skip-if-exists).
     *   2. Refresh the .gitignore block for all Composer-managed WP packages.
     */
    public function onPostInstallOrUpdate(Event $event): void
    {
        // ── 1. Autoloader mu-plugin ───────────────────────────────────────────
        $this->io->write('<info>WP Core Installer:</info> Checking Composer autoloader mu-plugin…');

        (new MuPluginScaffolder($this->composer, $this->io))->scaffold();

        // ── 2. .gitignore packages block ──────────────────────────────────────
        $this->io->write('<info>WP Core Installer:</info> Refreshing .gitignore for Composer-managed packages…');

        (new PackageGitignoreHandler(
            $this->composer,
            $this->io,
            new GitignoreManager($this->io)
        ))->handle();
    }
}