<?php

/**
 * WordPress configuration, read from the environment.
 *
 * Created once by kanopi/wp-core-installer (extra.wp-core-installer.
 * scaffold-wp-config) and never overwritten: this file is yours to edit and
 * commit. It contains no secrets; set them in the environment (or in a
 * .env file at the project root when vlucas/phpdotenv is installed).
 *
 *   DB_NAME, DB_USER, DB_PASSWORD, DB_HOST (default "localhost"),
 *   DB_CHARSET (default "utf8mb4"), DB_COLLATE, WP_TABLE_PREFIX (default "wp_"),
 *   WP_ENVIRONMENT_TYPE, WP_DEBUG, WP_HOME, WP_SITEURL,
 *   AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY,
 *   AUTH_SALT, SECURE_AUTH_SALT, LOGGED_IN_SALT, NONCE_SALT
 *
 * Unset keys/salts are simply not defined; WordPress then generates them and
 * stores them in the database (see wp_salt()).
 */

// Composer autoloader first, so .env support and packages are available
// before WordPress loads (the autoloader mu-plugin's require_once is then a no-op).
if (is_file(__DIR__ . '/{{AUTOLOAD_RELATIVE_PATH}}')) {
    require_once __DIR__ . '/{{AUTOLOAD_RELATIVE_PATH}}';
}

if (class_exists(\Dotenv\Dotenv::class) && is_file(__DIR__ . '/{{PROJECT_ROOT_RELATIVE_PATH}}.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/{{PROJECT_ROOT_RELATIVE_PATH}}')->safeLoad();
}

$wpci_env = static function (string $name, ?string $default = null): ?string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_NAME', (string) $wpci_env('DB_NAME', ''));
define('DB_USER', (string) $wpci_env('DB_USER', ''));
define('DB_PASSWORD', (string) $wpci_env('DB_PASSWORD', ''));
define('DB_HOST', (string) $wpci_env('DB_HOST', 'localhost'));
define('DB_CHARSET', (string) $wpci_env('DB_CHARSET', 'utf8mb4'));
define('DB_COLLATE', (string) $wpci_env('DB_COLLATE', ''));

$table_prefix = (string) $wpci_env('WP_TABLE_PREFIX', 'wp_');

// ── Authentication keys and salts (environment only) ─────────────────────────
foreach (
    [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ] as $wpci_key
) {
    $wpci_value = $wpci_env($wpci_key);
    if ($wpci_value !== null && !defined($wpci_key)) {
        define($wpci_key, $wpci_value);
    }
}

// ── Environment ──────────────────────────────────────────────────────────────
if ($wpci_env('WP_ENVIRONMENT_TYPE') !== null) {
    define('WP_ENVIRONMENT_TYPE', (string) $wpci_env('WP_ENVIRONMENT_TYPE'));
}

define('WP_DEBUG', filter_var($wpci_env('WP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));

foreach (['WP_HOME', 'WP_SITEURL'] as $wpci_key) {
    $wpci_value = $wpci_env($wpci_key);
    if ($wpci_value !== null) {
        define($wpci_key, $wpci_value);
    }
}

unset($wpci_env, $wpci_key, $wpci_value);

// ── Bootstrap ────────────────────────────────────────────────────────────────
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
