# Shared helpers for the wp-core-installer bats integration tests.
#
# Each test gets its own throwaway project built from fixture path repos
# (a fake "wordpress-core" package + this plugin). Everything is offline:
# path repositories + packagist disabled, so no network access is needed.

# Resolve the plugin repo root (three dirs up: tests/integration/ -> repo).
_repo_root() {
  cd "${BATS_TEST_DIRNAME}/../.." && pwd
}

# Build a throwaway project under BATS_TEST_TMPDIR.
# Sets the globals: WORK, CORE, PROJ.
setup_project() {
  REPO_ROOT="$(_repo_root)"
  COMPOSER="${COMPOSER_BIN:-composer}"
  export COMPOSER_NO_INTERACTION=1

  WORK="$(mktemp -d "${BATS_TEST_TMPDIR}/wpci-XXXXXX")"
  CORE="${WORK}/fake-core"
  PROJ="${WORK}/project"
  mkdir -p "${CORE}/wp-admin" "${CORE}/wp-includes" \
           "${CORE}/wp-content/themes/twentytwentyfive" "${PROJ}"

  # Fake wordpress-core package ------------------------------------------------
  cat > "${CORE}/composer.json" <<EOF
{ "name": "fake/wordpress-core", "version": "6.8.3", "type": "wordpress-core" }
EOF
  printf '<?php // wp-load\n'    > "${CORE}/wp-load.php"
  printf '<?php // admin\n'      > "${CORE}/wp-admin/index.php"
  printf '<?php // includes\n'   > "${CORE}/wp-includes/version.php"
  printf '# core htaccess\n'     > "${CORE}/.htaccess"             # skip-if-exists
  printf '<?php // sample\n'     > "${CORE}/wp-config-sample.php"  # skip-if-exists
  printf '<?php // real cfg\n'   > "${CORE}/wp-config.php"         # ALWAYS protected
  # silence-is-golden stub: skip-if-exists, allowed through on first install:
  printf '<?php // stub\n'       > "${CORE}/wp-content/themes/index.php"
  # a real bundled theme asset: lives under the protected wp-content/themes
  # dir and must NEVER be deployed:
  printf 'body{}\n'              > "${CORE}/wp-content/themes/twentytwentyfive/style.css"

  # Root project ---------------------------------------------------------------
  cat > "${PROJ}/composer.json" <<EOF
{
    "name": "test/project",
    "type": "project",
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": {
        "packagist.org": false,
        "core":   { "type": "path", "url": "../fake-core", "options": { "symlink": false } },
        "plugin": { "type": "path", "url": "${REPO_ROOT}",  "options": { "symlink": false } }
    },
    "require": {},
    "config": { "allow-plugins": { "kanopi/wp-core-installer": true } },
    "extra": { "wordpress-install-dir": "web" }
}
EOF
}

# Run composer inside the throwaway project.
composer_in_project() {
  ( cd "${PROJ}" && "${COMPOSER}" "$@" )
}
