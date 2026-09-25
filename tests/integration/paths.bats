#!/usr/bin/env bats
#
# Path resolution for wordpress-install-dir / mu-plugins-dir (#8, #13, #14).
# Core, the autoloader mu-plugin and every .gitignore entry must agree on
# one normalised web-root, whatever form the configured value takes.

load 'helpers'

setup() {
  setup_project
}

# The autoloader must sit in {webroot}/wp-content/mu-plugins and nowhere else.
assert_mu_plugin_in() {
  local webroot="$1"
  [ -f "${webroot}/wp-content/mu-plugins/000-autoloader.php" ]
  # And it must actually resolve to the project's vendor/autoload.php.
  ( cd "${webroot}/wp-content/mu-plugins" &&
    rel="$(sed -n "s#^\$autoloader = __DIR__ . '/\(.*\)';#\1#p" 000-autoloader.php)" &&
    [ -f "$rel" ] )
}

@test "omitted install dir deploys core and mu-plugin under public/" {
  set_extra '{}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/public/wp-load.php" ]
  assert_mu_plugin_in "${PROJ}/public"
  [ ! -e "${PROJ}/wp-content" ]
  grep -qx '/public/wp-content/mu-plugins/000-autoloader.php' "${PROJ}/.gitignore"
}

@test "nested install dir public/wp keeps core and mu-plugin together" {
  set_extra '{"wordpress-install-dir": "public/wp"}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/public/wp/wp-admin/index.php" ]
  assert_mu_plugin_in "${PROJ}/public/wp"
  grep -qx '/public/wp/wp-admin/' "${PROJ}/.gitignore"
}

@test "./public and public/ normalise to /public/ in .gitignore" {
  for dir in "./public" "public/" "public//"; do
    rm -rf "${PROJ}/public" "${PROJ}/.gitignore" "${PROJ}/vendor" "${PROJ}/composer.lock"
    set_extra "{\"wordpress-install-dir\": \"${dir}\"}"

    run install_core
    [ "$status" -eq 0 ]

    [ -f "${PROJ}/public/wp-load.php" ]
    grep -qx '/public/wp-admin/' "${PROJ}/.gitignore"
    ! grep -q '/\./\|//' "${PROJ}/.gitignore" || false
  done
}

@test "absolute install dir outside the project deploys there, no bogus .gitignore lines" {
  set_extra "{\"wordpress-install-dir\": \"$(native_path "${WORK}")/external-web\"}"

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${WORK}/external-web/wp-load.php" ]
  assert_mu_plugin_in "${WORK}/external-web"
  # Nothing outside the project may leak into the project's .gitignore.
  ! grep -q '^//' "${PROJ}/.gitignore" || false
  ! grep -q 'external-web' "${PROJ}/.gitignore" || false
  grep -qx '/vendor/' "${PROJ}/.gitignore"
}

@test "running with --working-dir from elsewhere writes into the project" {
  run bash -c "cd '${WORK}' && '${COMPOSER}' --working-dir=project require 'kanopi/wp-core-installer:*' 'fake/wordpress-core:*'"
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-load.php" ]
  [ -f "${PROJ}/.gitignore" ]
  [ ! -e "${WORK}/web" ]
  [ ! -e "${WORK}/.gitignore" ]
}
