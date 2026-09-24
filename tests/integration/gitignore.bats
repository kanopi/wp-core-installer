#!/usr/bin/env bats
#
# .gitignore block management and the manage-gitignore opt-out (#2).

load 'helpers'

setup() {
  setup_project
}

@test "manage-gitignore false writes no .gitignore but still deploys" {
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"manage-gitignore": false}}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-admin/index.php" ]
  [ -f "${PROJ}/web/wp-content/mu-plugins/000-autoloader.php" ]
  [ ! -e "${PROJ}/.gitignore" ]
}

@test "turning manage-gitignore off strips existing blocks and keeps user lines" {
  printf '/my-own-rule\n' > "${PROJ}/.gitignore"
  run install_core
  [ "$status" -eq 0 ]
  grep -q 'wp-core-installer:core:begin' "${PROJ}/.gitignore"
  grep -q 'wp-core-installer:packages:begin' "${PROJ}/.gitignore"

  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"manage-gitignore": false}}'
  run composer_in_project install
  [ "$status" -eq 0 ]

  ! grep -q 'kanopi/wp-core-installer' "${PROJ}/.gitignore"
  grep -qx '/my-own-rule' "${PROJ}/.gitignore"
}

@test "manage-gitignore can disable a single block" {
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"manage-gitignore": {"core": false}}}'

  run install_core
  [ "$status" -eq 0 ]

  ! grep -q 'wp-core-installer:core:begin' "${PROJ}/.gitignore"
  grep -q 'wp-core-installer:packages:begin' "${PROJ}/.gitignore"
  grep -qx '/vendor/' "${PROJ}/.gitignore"
}
