#!/usr/bin/env bats
#
# Core is not re-copied when nothing changed (#11).

load 'helpers'

setup() {
  setup_project
}

mtime() { php -r 'clearstatcache(); echo filemtime($argv[1]);' "$1"; }

@test "a fresh install deploys core once, not twice" {
  run install_core
  [ "$status" -eq 0 ]

  [ "$(grep -c 'Deploying fake/wordpress-core' <<<"$output")" -eq 1 ]
  ! grep -q 'Ensuring fake/wordpress-core' <<<"$output" || false
}

@test "an unchanged composer install skips the copy and leaves files untouched" {
  run install_core
  [ "$status" -eq 0 ]
  touch -t 200001010000 "${PROJ}/web/wp-load.php"
  before="$(mtime "${PROJ}/web/wp-load.php")"

  run composer_in_project install
  [ "$status" -eq 0 ]

  [[ "$output" == *"up to date in the web-root; skipping deploy"* ]] || false
  [ "$(mtime "${PROJ}/web/wp-load.php")" = "$before" ]
  grep -qx '/web/wp-admin/' "${PROJ}/.gitignore"
}

@test "a missing core file (e.g. cached vendor, fresh checkout) triggers a redeploy" {
  run install_core
  [ "$status" -eq 0 ]
  rm -rf "${PROJ}/web/wp-admin"

  run composer_in_project install
  [ "$status" -eq 0 ]

  [[ "$output" == *"Ensuring fake/wordpress-core is deployed"* ]] || false
  [ -f "${PROJ}/web/wp-admin/index.php" ]
}

@test "changing protection settings triggers a redeploy" {
  run install_core
  [ "$status" -eq 0 ]
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"skip-if-exists": ["wp-load.php"]}}'

  run composer_in_project install
  [ "$status" -eq 0 ]

  [[ "$output" == *"Ensuring fake/wordpress-core is deployed"* ]] || false
}

@test "toggling manage-gitignore takes effect even when the deploy is skipped" {
  run install_core
  [ "$status" -eq 0 ]
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"manage-gitignore": {"core": false}}}'

  run composer_in_project install
  [ "$status" -eq 0 ]

  [[ "$output" == *"skipping deploy"* ]] || false
  ! grep -q 'wp-core-installer:core:begin' "${PROJ}/.gitignore" || false
}
