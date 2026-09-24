#!/usr/bin/env bats
#
# Opt-in first-install wp-config.php scaffolding (#19).

load 'helpers'

setup() {
  setup_project
  CFG="${PROJ}/web/wp-config.php"
}

enable() {
  set_extra "{\"wordpress-install-dir\": \"web\", \"wp-core-installer\": {\"scaffold-wp-config\": true${1:+, $1}}}"
}

# Run the generated wp-config.php against a stub wp-settings.php that dumps
# what it defined. Extra args are VAR=value environment assignments.
run_config() {
  cat > "${PROJ}/web/wp-settings.php" <<'PHP'
<?php
echo json_encode([
    'DB_NAME'   => DB_NAME,
    'DB_HOST'   => DB_HOST,
    'prefix'    => $table_prefix,
    'WP_DEBUG'  => WP_DEBUG,
    'AUTH_KEY'  => defined('AUTH_KEY') ? AUTH_KEY : null,
    'NONCE_SALT'=> defined('NONCE_SALT') ? NONCE_SALT : null,
    'env_type'  => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : null,
    'abspath'   => ABSPATH,
]);
PHP
  run env "$@" php "${CFG}"
}

@test "no wp-config.php is created by default" {
  run install_core
  [ "$status" -eq 0 ]
  [ ! -e "$CFG" ]
}

@test "scaffolded wp-config.php reads everything from the environment" {
  enable
  run install_core
  [ "$status" -eq 0 ]
  [[ "$output" == *"Created web/wp-config.php"* ]]
  php -l "$CFG"

  run_config DB_NAME=site DB_HOST=db AUTH_KEY=k1 NONCE_SALT=s8 WP_TABLE_PREFIX=kp_ WP_DEBUG=true WP_ENVIRONMENT_TYPE=staging
  [ "$status" -eq 0 ]
  [[ "$output" == *'"DB_NAME":"site"'* ]]
  [[ "$output" == *'"DB_HOST":"db"'* ]]
  [[ "$output" == *'"prefix":"kp_"'* ]]
  [[ "$output" == *'"WP_DEBUG":true'* ]]
  [[ "$output" == *'"AUTH_KEY":"k1"'* ]]
  [[ "$output" == *'"NONCE_SALT":"s8"'* ]]
  [[ "$output" == *'"env_type":"staging"'* ]]
}

@test "no secrets are written and unset salts stay undefined" {
  enable
  run install_core
  [ "$status" -eq 0 ]
  ! grep -qE "define\('AUTH_KEY', *'" "$CFG"

  run_config DB_NAME=site
  [ "$status" -eq 0 ]
  [[ "$output" == *'"AUTH_KEY":null'* ]]
  [[ "$output" == *'"DB_HOST":"localhost"'* ]]
  [[ "$output" == *'"prefix":"wp_"'* ]]
  [[ "$output" == *'"WP_DEBUG":false'* ]]
}

@test "an existing wp-config.php is never overwritten" {
  enable
  mkdir -p "${PROJ}/web"
  printf '<?php // mine\n' > "$CFG"

  run install_core
  [ "$status" -eq 0 ]
  [ "$(cat "$CFG")" = "<?php // mine" ]
}

@test "a wp-config.php one level above the web-root is respected" {
  enable
  printf '<?php // above\n' > "${PROJ}/wp-config.php"

  run install_core
  [ "$status" -eq 0 ]
  [ ! -e "$CFG" ]
  [[ "$output" == *"WordPress already loads"* ]]
}

@test "a custom template gets its placeholders filled in" {
  mkdir -p "${PROJ}/config"
  printf "<?php\n// autoload: {{AUTOLOAD_RELATIVE_PATH}}\n// root: {{PROJECT_ROOT_RELATIVE_PATH}}\n" > "${PROJ}/config/wp-config.tpl.php"
  enable '"wp-config-template": "config/wp-config.tpl.php"'

  run install_core
  [ "$status" -eq 0 ]
  grep -qx '// autoload: ../vendor/autoload.php' "$CFG"
  grep -qx '// root: ../' "$CFG"
}

@test "a missing custom template fails clearly" {
  enable '"wp-config-template": "config/nope.php"'

  run install_core
  [ "$status" -ne 0 ]
  [[ "$output" == *"wp-config template not found"* ]]
}

@test "a .env file is loaded when vlucas/phpdotenv is installed" {
  # Minimal stand-in for vlucas/phpdotenv (offline suite): same class and
  # method names, parses KEY=value lines into $_ENV.
  local dir="${WORK}/fixtures/vlucas-phpdotenv"
  mkdir -p "${dir}/src"
  cat > "${dir}/composer.json" <<'JSON'
{ "name": "vlucas/phpdotenv", "version": "5.6.0", "autoload": { "psr-4": { "Dotenv\\": "src/" } } }
JSON
  cat > "${dir}/src/Dotenv.php" <<'PHP'
<?php
namespace Dotenv;
final class Dotenv {
    private function __construct(private string $dir) {}
    public static function createImmutable(string $dir): self { return new self($dir); }
    public function safeLoad(): void {
        foreach (file($this->dir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$k, $v] = explode('=', $line, 2);
            $_ENV[$k] ??= $v;
        }
    }
}
PHP
  enable
  printf 'DB_NAME=from_dotenv\n' > "${PROJ}/.env"

  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*" "vlucas/phpdotenv:*"
  [ "$status" -eq 0 ]

  run_config
  [ "$status" -eq 0 ]
  [[ "$output" == *'"DB_NAME":"from_dotenv"'* ]]
}
