#!/usr/bin/env bats
#
# User configuration under extra.wp-core-installer.

load 'helpers'

setup() {
  setup_project
}

@test "custom protected-paths are never deployed" {
  mkdir -p "${CORE}/config"
  printf 'core copy\n' > "${CORE}/config/app.php"
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"protected-paths": ["config", "wp-load.php"]}}'

  run install_core
  [ "$status" -eq 0 ]

  [ ! -e "${PROJ}/web/config" ]
  [ ! -e "${PROJ}/web/wp-load.php" ]
  [ -f "${PROJ}/web/wp-admin/index.php" ]
  ! grep -q '/web/wp-load.php' "${PROJ}/.gitignore" || false
}

@test "custom skip-if-exists files are copied once and never overwritten" {
  printf 'core robots\n' > "${CORE}/robots.txt"
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"skip-if-exists": ["robots.txt"]}}'

  run install_core
  [ "$status" -eq 0 ]
  [ "$(cat "${PROJ}/web/robots.txt")" = "core robots" ]

  printf 'my robots\n' > "${PROJ}/web/robots.txt"
  printf 'newer core robots\n' > "${CORE}/robots.txt"
  run update_core_to 6.9.0
  [ "$status" -eq 0 ]

  [ "$(cat "${PROJ}/web/robots.txt")" = "my robots" ]
  ! grep -q 'robots.txt' "${PROJ}/.gitignore" || false
}

@test "vendor-dir inside mu-plugins: autoloader and .gitignore follow it" {
  php -r '
    $f = $argv[1]; $j = json_decode(file_get_contents($f));
    $j->config->{"vendor-dir"} = "web/wp-content/mu-plugins/vendor";
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  ' "${PROJ}/composer.json"

  run install_core
  [ "$status" -eq 0 ]

  grep -q "__DIR__ . '/vendor/autoload.php'" "${PROJ}/web/wp-content/mu-plugins/000-autoloader.php"
  grep -qx '/web/wp-content/mu-plugins/vendor/' "${PROJ}/.gitignore"
  grep -qx '/web/wp-content/mu-plugins/vendor/.wordpress-core-staging/' "${PROJ}/.gitignore"
}

@test "an invalid setting fails with a message naming it" {
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"protected-paths": "config"}}'

  run install_core
  [ "$status" -ne 0 ]
  # Composer's console wraps long messages, so match the pieces.
  [[ "$output" == *"extra.wp-core-installer.protected-paths"* ]] || false
  [[ "$output" == *"must be an array of strings"* ]] || false
}
