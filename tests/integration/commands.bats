#!/usr/bin/env bats
#
# composer wp-core:deploy / wp-core:status / wp-core:verify (#18).

load 'helpers'

setup() {
  setup_project
  run install_core
  [ "$status" -eq 0 ]
}

mtime() { php -r 'clearstatcache(); echo filemtime($argv[1]);' "$1"; }

@test "wp-core:status reports in sync and exits 0" {
  run composer_in_project wp-core:status
  [ "$status" -eq 0 ]
  [[ "$output" == *"In sync"* ]] || false
  [[ "$output" == *"fake/wordpress-core 6.8.3"* ]] || false
}

@test "wp-core:status reports drift and exits 1" {
  printf '<?php // hacked\n' > "${PROJ}/web/wp-includes/version.php"
  rm "${PROJ}/web/wp-admin/index.php"

  run composer_in_project wp-core:status -v
  [ "$status" -eq 1 ]
  [[ "$output" == *"Out of sync"* ]] || false
  [[ "$output" == *"1 to create, 1 to update"* ]] || false
  [[ "$output" == *"wp-includes/version.php"* ]] || false
  [[ "$output" == *"wp-admin/index.php"* ]] || false
}

@test "wp-core:deploy --dry-run reports changes without writing" {
  printf '<?php // hacked\n' > "${PROJ}/web/wp-includes/version.php"

  run composer_in_project wp-core:deploy --dry-run
  [ "$status" -eq 0 ]
  [[ "$output" == *"would change: 1 to update"* ]] || false
  [ "$(cat "${PROJ}/web/wp-includes/version.php")" = "<?php // hacked" ]
}

@test "wp-core:deploy restores drifted files and leaves unchanged ones alone" {
  printf '<?php // hacked\n' > "${PROJ}/web/wp-includes/version.php"
  touch -t 200001010000 "${PROJ}/web/wp-load.php"
  before="$(mtime "${PROJ}/web/wp-load.php")"

  run composer_in_project wp-core:deploy
  [ "$status" -eq 0 ]
  [[ "$output" == *"1 updated"* ]] || false
  [ "$(cat "${PROJ}/web/wp-includes/version.php")" = "<?php // includes" ]
  [ "$(mtime "${PROJ}/web/wp-load.php")" = "$before" ]

  run composer_in_project wp-core:status
  [ "$status" -eq 0 ]
}

@test "wp-core:deploy --force rewrites unchanged files too" {
  touch -t 200001010000 "${PROJ}/web/wp-load.php"
  before="$(mtime "${PROJ}/web/wp-load.php")"

  run composer_in_project wp-core:deploy --force
  [ "$status" -eq 0 ]
  [ "$(mtime "${PROJ}/web/wp-load.php")" != "$before" ]
}

@test "wp-core:verify passes against matching checksums" {
  write_core_checksums "${WORK}/checksums.json"

  run composer_in_project wp-core:verify --checksums-file="$(native_path "${WORK}")/checksums.json"
  [ "$status" -eq 0 ]
  [[ "$output" == *"Success:"* ]] || false
}

@test "wp-core:verify fails on modified or missing files, warns on unexpected ones" {
  write_core_checksums "${WORK}/checksums.json"
  printf '<?php // hacked\n' > "${PROJ}/web/wp-includes/version.php"
  rm "${PROJ}/web/wp-admin/index.php"
  printf '<?php // dropped in\n' > "${PROJ}/web/wp-includes/backdoor.php"

  run composer_in_project wp-core:verify --checksums-file="$(native_path "${WORK}")/checksums.json"
  [ "$status" -eq 1 ]
  [[ "$output" == *"modified   wp-includes/version.php"* ]] || false
  [[ "$output" == *"missing    wp-admin/index.php"* ]] || false
  [[ "$output" == *"unexpected wp-includes/backdoor.php"* ]] || false
}

@test "wp-core:verify ignores skip-if-exists and protected files" {
  write_core_checksums "${WORK}/checksums.json"
  printf '<?php // my sample\n' > "${PROJ}/web/wp-config-sample.php"

  run composer_in_project wp-core:verify --checksums-file="$(native_path "${WORK}")/checksums.json"
  [ "$status" -eq 0 ]
  [[ "$output" != *"wp-config-sample.php"* ]] || false
  [[ "$output" != *"twentytwentyfive"* ]] || false
}

@test "commands fail clearly when no core package is installed" {
  run composer_in_project remove fake/wordpress-core
  [ "$status" -eq 0 ]

  run composer_in_project wp-core:status
  [ "$status" -eq 1 ]
  [[ "$output" == *"No wordpress-core package is installed"* ]] || false
}
