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
           "${CORE}/wp-content/themes/twentytwentyfive" "${PROJ}" "${WORK}/fixtures"

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
        "plugin": { "type": "path", "url": "${REPO_ROOT}",  "options": { "symlink": false } },
        "installers": { "type": "path", "url": "${REPO_ROOT}/vendor/composer/installers", "options": { "symlink": false, "versions": { "composer/installers": "2.99.0" } } },
        "fixtures": { "type": "path", "url": "../fixtures/*", "options": { "symlink": false } }
    },
    "require": {},
    "config": { "allow-plugins": { "kanopi/wp-core-installer": true, "composer/installers": true } },
    "extra": { "wordpress-install-dir": "web" }
}
EOF
}

# Run composer inside the throwaway project.
composer_in_project() {
  ( cd "${PROJ}" && "${COMPOSER}" "$@" )
}

# Override extra.wordpress-install-dir in the project's composer.json.
# Used to exercise the "." (web root == project root) configuration.
set_install_dir() {
  local dir="$1"
  sed -i.bak "s#\"wordpress-install-dir\": \"web\"#\"wordpress-install-dir\": \"${dir}\"#" \
    "${PROJ}/composer.json"
  rm -f "${PROJ}/composer.json.bak"
}

# Replace the project's entire extra block with the given JSON object.
# e.g. set_extra '{"wordpress-install-dir": "./public"}'
set_extra() {
  php -r '
    $f = $argv[1];
    $j = json_decode(file_get_contents($f));
    $j->extra = json_decode($argv[2], false, 512, JSON_THROW_ON_ERROR);
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' "${PROJ}/composer.json" "$1"
}

# Install core + this plugin together (the common fresh-install shape).
install_core() {
  composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*"
}

# Create a fixture WordPress package (installed via composer/installers).
# Usage: make_wp_package <vendor/name> <type>
# The composer/installers path repo is taken from this repo's own vendor/,
# so run `composer install` in the plugin repo before the suite.
make_wp_package() {
  local name="$1" type="$2"
  local dir="${WORK}/fixtures/${name//\//-}"
  mkdir -p "$dir"
  cat > "${dir}/composer.json" <<JSON
{ "name": "${name}", "version": "1.0.0", "type": "${type}", "require": { "composer/installers": "*" } }
JSON
  printf '<?php // %s\n' "$name" > "${dir}/main.php"
}
