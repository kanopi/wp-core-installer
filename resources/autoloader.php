<?php

/**
 * Plugin Name:  Composer Autoloader
 * Description:  Bootstraps the Composer autoloader so all Composer-managed
 *               packages are available throughout WordPress.
 * Version:      1.0.0
 * Author:       kanopi/wp-core-installer
 * License:      MIT
 *
 * This file is generated and overwritten on every `composer install` and
 * `composer update` by kanopi/wp-core-installer.  Do not edit it manually —
 * your changes will be lost on the next Composer run.
 *
 * To manage this file yourself, set in your composer.json:
 *
 *   "extra": {
 *       "wp-core-installer": {
 *           "manage-mu-plugin-autoloader": false
 *       }
 *   }
 *
 * Then remove this file from .gitignore and commit your own version.
 */

declare(strict_types=1);

// Guard: this file must only run inside a WordPress request.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Path to the Composer autoloader, relative to this file.
 * Computed and written by kanopi/wp-core-installer based on the
 * vendor-dir setting in composer.json at the time of scaffolding.
 *
 * If you move vendor/ you will need to update this path.
 */
$autoloader = __DIR__ . '/{{AUTOLOAD_RELATIVE_PATH}}';

if (!is_file($autoloader)) {
    // The autoloader is missing — most likely `composer install` has not been
    // run yet, or the vendor directory has been deleted.
    if (defined('WP_DEBUG') && WP_DEBUG) {
        trigger_error(
            sprintf(
                '[kanopi/wp-core-installer] Composer autoloader not found at "%s". '
                . 'Run `composer install` from the project root.',
                $autoloader
            ),
            E_USER_WARNING
        );
    }

    return;
}

require_once $autoloader;