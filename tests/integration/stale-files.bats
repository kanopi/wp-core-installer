#!/usr/bin/env bats
#
# Files dropped by a newer core release are removed from the web-root (#9).

load 'helpers'

setup() {
  setup_project
  mkdir -p "${CORE}/wp-includes/legacy"
  printf '<?php // old\n' > "${CORE}/wp-includes/legacy/old.php"
  run install_core
  [ "$status" -eq 0 ]
  [ -f "${PROJ}/web/wp-includes/legacy/old.php" ]
}

@test "a file core no longer ships is deleted and its empty dir pruned" {
  rm -rf "${CORE}/wp-includes/legacy"

  run update_core_to 6.9.0
  [ "$status" -eq 0 ]

  [ ! -e "${PROJ}/web/wp-includes/legacy" ]
  [ -f "${PROJ}/web/wp-includes/version.php" ]
  [[ "$output" == *"Removed 1 stale file(s)"* ]]
}

@test "files the project added inside core dirs are never deleted" {
  printf '<?php // mine\n' > "${PROJ}/web/wp-includes/my-hack.php"
  rm -rf "${CORE}/wp-includes/legacy"

  run update_core_to 6.9.0
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-includes/my-hack.php" ]
}

@test "skip-if-exists files are kept even when core drops them" {
  rm "${CORE}/wp-config-sample.php"

  run update_core_to 6.9.0
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-config-sample.php" ]
}

@test "without a previous manifest (upgrade from 1.1.x) nothing is deleted" {
  rm "${PROJ}/vendor/.wordpress-core-staging/.deploy-manifest.json"
  rm -rf "${CORE}/wp-includes/legacy"

  run update_core_to 6.9.0
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-includes/legacy/old.php" ]
  # ...and the manifest is re-created, so the next update can clean up.
  [ -f "${PROJ}/vendor/.wordpress-core-staging/.deploy-manifest.json" ]
}
