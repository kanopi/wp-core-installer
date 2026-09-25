<?php

declare(strict_types=1);

namespace Kanopi\Composer\WordPress;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
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
 *   3. Provides the wp-core:deploy, wp-core:status and wp-core:verify commands.
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;
    private CoreInstaller $coreInstaller;

    // -------------------------------------------------------------------------
    // PluginInterface
    // -------------------------------------------------------------------------

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io       = $io;

        $this->coreInstaller = new CoreInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->coreInstaller);
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
    // Capable
    // -------------------------------------------------------------------------

    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => Command\CommandProvider::class];
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
     *   0. Make sure core is deployed.
     *   1. Create a starter wp-config.php when opted in and none exists.
     *   2. Scaffold the Composer autoloader mu-plugin.
     *   3. Copy copy-to packages into their shared target folders.
     *   4. Refresh the .gitignore block for all Composer-managed WP packages.
     */
    public function onPostInstallOrUpdate(Event $event): void
    {
        // ── 0. Ensure core is deployed (handles vendor-cache scenario) ───────
        $this->coreInstaller->ensureCoreDeployed();

        // ── 1. Starter wp-config.php (opt-in, first install only) ─────────────
        (new WpConfigScaffolder($this->composer, $this->io))->scaffold();

        // ── 2. Autoloader mu-plugin ───────────────────────────────────────────
        $this->io->write('<info>WP Core Installer:</info> Checking Composer autoloader mu-plugin…');

        (new MuPluginScaffolder($this->composer, $this->io))->scaffold();

        // ── 3. copy-to: place packages that must live in shared folders ───────
        (new PackageCopier($this->composer, $this->io))->run();

        // ── 4. .gitignore packages block ──────────────────────────────────────
        $this->io->write('<info>WP Core Installer:</info> Refreshing .gitignore for Composer-managed packages…');

        (new PackageGitignoreHandler(
            $this->composer,
            $this->io,
            new GitignoreManager($this->io, (new ProjectPaths($this->composer))->pluginConfig())
        ))->handle();
    }
}
