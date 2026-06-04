#!/usr/bin/env bats
#
# End-to-end lifecycle tests for kanopi/wp-core-installer.
#
# These drive the REAL composer binary against fixture path packages and
# assert on the resulting web-root / .gitignore. They exercise the Composer
# plugin lifecycle, which is where the timing bugs live (and where unit tests
# can't reach).
#
# Run:  bats tests/integration/
# Deps: bats-core, composer, php (>=8.0).

load 'helpers'

setup() {
  setup_project
}

# Scenario 1 — the regression that prompted the fix.
# Requiring the plugin AFTER core is already installed must deploy core in a
# SINGLE step, with no second `composer install`.
@test "require plugin after core already installed deploys in one step" {
  run composer_in_project require "fake/wordpress-core:*"
  [ "$status" -eq 0 ]
  # Nothing should be in the web-root yet — the plugin isn't installed.
  [ ! -f "${PROJ}/web/wp-admin/index.php" ]

  run composer_in_project require "kanopi/wp-core-installer:*"
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-admin/index.php" ]
  [ -f "${PROJ}/web/wp-includes/version.php" ]
  [ -f "${PROJ}/web/wp-load.php" ]
}

# Scenario 2 — fresh install with both packages required together (staging path).
@test "fresh install with core and plugin together deploys core" {
  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-admin/index.php" ]
  [ -f "${PROJ}/web/wp-includes/version.php" ]
  [ -f "${PROJ}/web/wp-load.php" ]
}

# Scenario 3 — always-protected paths are never written from core.
@test "wp-config.php and bundled themes are never deployed (protected)" {
  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
  [ "$status" -eq 0 ]

  [ ! -f "${PROJ}/web/wp-config.php" ]
  [ ! -f "${PROJ}/web/wp-content/themes/twentytwentyfive/style.css" ]
}

# Scenario 3b — the silence-is-golden index.php stub IS allowed through on
# first install, even though wp-content/themes is otherwise protected
# (skip-if-exists takes precedence — verifies tier ordering).
@test "wp-content/themes/index.php stub is deployed on first install" {
  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-content/themes/index.php" ]
}

# Scenario 4 — skip-if-exists: a user-owned file is preserved, not overwritten.
@test "existing .htaccess is preserved (skip-if-exists)" {
  mkdir -p "${PROJ}/web"
  printf 'MY CUSTOM HTACCESS\n' > "${PROJ}/web/.htaccess"

  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
  [ "$status" -eq 0 ]

  [ "$(cat "${PROJ}/web/.htaccess")" = "MY CUSTOM HTACCESS" ]
}

# Scenario 5 — the .gitignore "core" block is written for deployed core paths.
@test "gitignore core block is written for deployed paths" {
  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/.gitignore" ]
  grep -q "wp-admin" "${PROJ}/.gitignore"
}

# Scenario 6 — regression for the stripBlock preg_replace crash.
# Reinstalling core runs the uninstall path; it must not error.
@test "reinstalling core does not crash and redeploys" {
  run composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
  [ "$status" -eq 0 ]

  run composer_in_project reinstall fake/wordpress-core
  [ "$status" -eq 0 ]
  [ -f "${PROJ}/web/wp-admin/index.php" ]
}
