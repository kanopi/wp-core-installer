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

# Regression for #7: skip-if-exists files belong to the project after first
# install, so they must never be gitignored — neither on the first install
# (when they are copied) nor on later runs (when they already exist).
@test "skip-if-exists files are never added to the core block" {
  run install_core
  [ "$status" -eq 0 ]
  run composer_in_project install
  [ "$status" -eq 0 ]

  block="$(sed -n '/core:begin/,/core:end/p' "${PROJ}/.gitignore")"
  ! grep -q 'htaccess'             <<<"$block"
  ! grep -q 'wp-config-sample.php' <<<"$block"
  ! grep -q 'index.php'            <<<"$block"

  grep -qx '/web/wp-admin/'    <<<"$block"
  grep -qx '/web/wp-includes/' <<<"$block"
  grep -qx '/web/wp-load.php'  <<<"$block"
}

# Packages block (#15): every WordPress content type composer/installers
# places is listed; packages that end up inside vendor/ are not duplicated.
@test "packages block lists plugins, themes, mu-plugins and drop-ins" {
  make_wp_package fixture/plugin-a    wordpress-plugin
  make_wp_package fixture/theme-a     wordpress-theme
  make_wp_package fixture/mu-a        wordpress-muplugin
  make_wp_package fixture/dropin-a    wordpress-dropin
  make_wp_package fixture/lang-a      wordpress-language
  set_extra '{
    "wordpress-install-dir": "web",
    "installer-paths": {
      "web/wp-content/plugins/{$name}/":    ["type:wordpress-plugin"],
      "web/wp-content/themes/{$name}/":     ["type:wordpress-theme"],
      "web/wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"],
      "web/wp-content/{$name}/":            ["type:wordpress-dropin"]
    }
  }'

  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*" \
    "fixture/plugin-a:*" "fixture/theme-a:*" "fixture/mu-a:*" "fixture/dropin-a:*" "fixture/lang-a:*"
  [ "$status" -eq 0 ]

  block="$(sed -n '/packages:begin/,/packages:end/p' "${PROJ}/.gitignore")"
  grep -qx '/web/wp-content/plugins/plugin-a/'  <<<"$block"
  grep -qx '/web/wp-content/themes/theme-a/'    <<<"$block"
  grep -qx '/web/wp-content/mu-plugins/mu-a/'   <<<"$block"
  grep -qx '/web/wp-content/dropin-a/'          <<<"$block"
  grep -q  'Composer-managed WordPress drop-ins' <<<"$block"

  # No installer handles wordpress-language here, so it lands in vendor/,
  # which is already ignored — it must not get its own line.
  [ -d "${PROJ}/vendor/fixture/lang-a" ]
  ! grep -q 'lang-a' <<<"$block"
}
