#!/usr/bin/env bats
#
# Host presets (#20).

load 'helpers'

setup() {
  setup_project
}

@test "pantheon: core in web/ and no managed .gitignore blocks" {
  set_extra '{"wp-core-installer": {"preset": "pantheon"}}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-admin/index.php" ]
  [ -f "${PROJ}/web/wp-content/mu-plugins/000-autoloader.php" ]
  [ ! -e "${PROJ}/.gitignore" ]
}

@test "wpengine: core in the project root" {
  set_extra '{"wp-core-installer": {"preset": "wpengine"}}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/wp-admin/index.php" ]
  grep -qx '/wp-admin/' "${PROJ}/.gitignore"
}

@test "explicit settings override the preset" {
  set_extra '{"wordpress-install-dir": "public", "wp-core-installer": {"preset": "pantheon", "manage-gitignore": true}}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/public/wp-admin/index.php" ]
  grep -qx '/public/wp-admin/' "${PROJ}/.gitignore"
}

@test "an unknown preset fails and lists the available ones" {
  set_extra '{"wp-core-installer": {"preset": "nope"}}'

  run install_core
  [ "$status" -ne 0 ]
  [[ "$output" == *'unknown preset "nope"'* ]]
  [[ "$output" == *"pantheon, wpengine"* ]]
}
