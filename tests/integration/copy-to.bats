#!/usr/bin/env bats
#
# copy-to (#46): copying files out of installed packages into folders other
# files share (mu-plugins/, wp-content/). Two shapes are covered:
#   - "vendor/package:path" — one file or folder out of a normally installed
#     package (a mu-plugin loader, an object-cache.php drop-in);
#   - "vendor/package" — a whole package laid out for a shared folder
#     (loader file + folder of the same name, e.g. host mu-plugin bundles).

load 'helpers'

MU=""

setup() {
  setup_project
  MU="${PROJ}/public/wp-content/mu-plugins"
  mkdir -p "${WORK}/artifacts" "${MU}"
  # The project's own mu-plugin, which must survive everything below.
  printf '<?php // mine\n' > "${MU}/my-plugin.php"
}

# Build a fake Kinsta MU plugin release as an artifact-repository zip.
# Usage: kinsta_release <version> [extra-file]
kinsta_release() {
  php -r '
    [$_, $dir, $version, $extra] = $argv + [3 => ""];
    $zip = new ZipArchive();
    $zip->open("$dir/kinsta-$version.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString("composer.json", json_encode([
        "name" => "kinsta/kinsta-mu-plugins", "version" => $version,
        "type" => "wordpress-muplugin", "require" => ["composer/installers" => "*"],
    ]));
    $zip->addFromString("kinsta-mu-plugins.php", "<?php // loader $version\n");
    $zip->addFromString("kinsta-mu-plugins/lib.php", "<?php // lib $version\n");
    if ($extra !== "") { $zip->addFromString("kinsta-mu-plugins/$extra", "<?php // $extra\n"); }
    $zip->close();
  ' "$(native_path "${WORK}/artifacts")" "$1" "${2:-}"
}

# Configure the project. $1 = installer-paths target for the package,
# $2 = extra wp-core-installer settings (JSON members, optional).
configure() {
  php -r '
    [$_, $file, $artifacts, $installTo, $settings] = $argv + [4 => ""];
    $j = json_decode(file_get_contents($file));
    $j->repositories->artifacts = (object) ["type" => "artifact", "url" => $artifacts];
    $j->config->{"vendor-dir"} = "public/wp-content/mu-plugins/vendor";
    $j->extra = json_decode("{
      \"wordpress-install-dir\": \"public\",
      \"installer-paths\": {
        \"$installTo\": [\"kinsta/kinsta-mu-plugins\"],
        \"public/wp-content/mu-plugins/{\$name}/\": [\"type:wordpress-muplugin\"]
      },
      \"wp-core-installer\": {" . $settings . "}
    }");
    file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  ' "${PROJ}/composer.json" "$(native_path "${WORK}/artifacts")" "$1" "${2:-}"
}

use_copy_to() {
  configure "public/wp-content/mu-plugins/vendor/kinsta/kinsta-mu-plugins/" \
    '"copy-to": {"kinsta/kinsta-mu-plugins": "public/wp-content/mu-plugins"}'
}

install_kinsta() {
  composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*" "kinsta/kinsta-mu-plugins:$1"
}

@test "copy-to places the package's files and gitignores only them" {
  kinsta_release 3.5.0
  use_copy_to

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]

  [ "$(cat "${MU}/kinsta-mu-plugins.php")" = "<?php // loader 3.5.0" ]
  [ -f "${MU}/kinsta-mu-plugins/lib.php" ]
  [ ! -e "${MU}/composer.json" ]
  [ -f "${MU}/my-plugin.php" ]

  block="$(sed -n '/packages:begin/,/packages:end/p' "${PROJ}/.gitignore")"
  grep -qx '/public/wp-content/mu-plugins/kinsta-mu-plugins.php' <<<"$block"
  grep -qx '/public/wp-content/mu-plugins/kinsta-mu-plugins/'    <<<"$block"
  ! grep -qx '/public/wp-content/mu-plugins/' <<<"$block" || false
  ! grep -q 'my-plugin' <<<"$block" || false
}

@test "updating the package keeps other mu-plugins and vendor, and drops stale files" {
  kinsta_release 3.5.0 old-helper.php
  kinsta_release 3.5.1
  use_copy_to
  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]
  [ -f "${MU}/kinsta-mu-plugins/old-helper.php" ]

  run install_kinsta 3.5.1
  [ "$status" -eq 0 ]

  [ "$(cat "${MU}/kinsta-mu-plugins.php")" = "<?php // loader 3.5.1" ]
  [ ! -e "${MU}/kinsta-mu-plugins/old-helper.php" ]
  [ -f "${MU}/my-plugin.php" ]
  [ -f "${MU}/000-autoloader.php" ]
  [ -d "${MU}/vendor" ]
}

@test "removing the package removes only what copy-to placed" {
  kinsta_release 3.5.0
  use_copy_to
  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]
  printf '<?php // mine too\n' > "${MU}/kinsta-mu-plugins/my-addition.php"

  run composer_in_project remove kinsta/kinsta-mu-plugins
  [ "$status" -eq 0 ]

  [ ! -e "${MU}/kinsta-mu-plugins.php" ]
  [ ! -e "${MU}/kinsta-mu-plugins/lib.php" ]
  [ -f "${MU}/kinsta-mu-plugins/my-addition.php" ]
  [ -f "${MU}/my-plugin.php" ]
  ! grep -q 'kinsta-mu-plugins' "${PROJ}/.gitignore" || false
}

@test "overwrite takes over an existing file at the destination, and says so" {
  kinsta_release 3.5.0
  use_copy_to
  # e.g. an older, previously committed copy of the same file
  printf '<?php // committed older copy\n' > "${MU}/kinsta-mu-plugins.php"

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]

  [ "$(cat "${MU}/kinsta-mu-plugins.php")" = "<?php // loader 3.5.0" ]
  [[ "$output" == *"replaced existing file(s) it now manages: kinsta-mu-plugins.php"* ]] || false
  [ -f "${MU}/my-plugin.php" ]
}

@test "an identical existing file is adopted, so it is updated and removed later" {
  kinsta_release 3.5.0
  kinsta_release 3.5.1
  use_copy_to
  printf '<?php // loader 3.5.0\n' > "${MU}/kinsta-mu-plugins.php"

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]
  [[ "$output" != *"left untouched"* ]] || false

  run install_kinsta 3.5.1
  [ "$status" -eq 0 ]
  [ "$(cat "${MU}/kinsta-mu-plugins.php")" = "<?php // loader 3.5.1" ]
}

@test "installing a package straight into a shared folder warns and is not gitignored wholesale" {
  kinsta_release 3.5.0
  configure "public/wp-content/mu-plugins/"

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]

  [[ "$output" == *"is installed directly into public/wp-content/mu-plugins, which other files share"* ]] || false
  [[ "$output" == *"copy-to"* ]] || false
  ! grep -qx '/public/wp-content/mu-plugins/' "${PROJ}/.gitignore" || false
}

@test "copy-to refuses to copy a package over its own install folder" {
  kinsta_release 3.5.0
  # composer/installers' default for this package: mu-plugins/kinsta-mu-plugins/,
  # the same path as the package's own kinsta-mu-plugins/ directory.
  configure "public/wp-content/mu-plugins/kinsta-mu-plugins/" \
    '"copy-to": {"kinsta/kinsta-mu-plugins": "public/wp-content/mu-plugins"}'

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]

  [[ "$output" == *"would be copied over the package's own install folder"* ]] || false
  [ ! -e "${MU}/kinsta-mu-plugins.php" ]
}

@test "invalid copy-to keys fail clearly" {
  set_extra '{"wordpress-install-dir": "public", "wp-core-installer": {"copy-to": {"not a package": "public/wp-content/"}}}'
  run install_core
  [ "$status" -ne 0 ]
  [[ "$output" == *'keys must look like "vendor/package" or "vendor/package:path/in/package"'* ]] || false

  set_extra '{"wordpress-install-dir": "public", "wp-core-installer": {"copy-to": {"acme/tools:../../etc/passwd": "public/wp-content/"}}}'
  run install_core
  [ "$status" -ne 0 ]
  [[ "$output" == *'must be relative to the package, without ".."'* ]] || false
}

# ── "vendor/package:path" entries ───────────────────────────────────────────

# A normal plugin package, installed to wp-content/plugins/tools/, shipping
# files that have to live elsewhere. Usage: tools_release <version> [variant]
# variant "v2": changed loader, no assets/old.css.
tools_release() {
  php -r '
    [$_, $dir, $version, $variant, $extra] = $argv + [3 => "", 4 => ""];
    $zip = new ZipArchive();
    $zip->open("$dir/tools-$version.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString("composer.json", json_encode([
        "name" => "acme/tools", "version" => $version,
        "type" => "wordpress-plugin", "require" => ["composer/installers" => "*"],
        "extra" => $extra === "" ? new stdClass() : json_decode($extra),
    ]));
    $zip->addFromString("rules.conf", "RewriteRule ^tools-$version$ /tools [L]\n");
    $zip->addFromString("tools.php", "<?php // plugin\n");
    $zip->addFromString("includes/object-cache.php", "<?php // drop-in $version\n");
    $zip->addFromString("loader.php", "<?php // loader $version\n");
    $zip->addFromString("assets/app.css", "body{}\n");
    if ($variant !== "v2") { $zip->addFromString("assets/old.css", "old{}\n"); }
    $zip->close();
  ' "$(native_path "${WORK}/artifacts")" "$1" "${2:-}" "${3:-}"
}

# $1 = copy-to JSON members, $2 = other wp-core-installer JSON members.
configure_tools() {
  php -r '
    [$_, $file, $artifacts, $copyTo, $more] = $argv + [4 => ""];
    $j = json_decode(file_get_contents($file));
    $j->repositories->artifacts = (object) ["type" => "artifact", "url" => $artifacts];
    $j->extra = json_decode("{
      \"wordpress-install-dir\": \"public\",
      \"installer-paths\": { \"public/wp-content/plugins/{\$name}/\": [\"type:wordpress-plugin\"] },
      \"wp-core-installer\": { \"copy-to\": {" . $copyTo . "}" . ($more === "" ? "" : ", " . $more) . " }
    }");
    file_put_contents($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  ' "${PROJ}/composer.json" "$(native_path "${WORK}/artifacts")" "$1" "${2:-}"
}

install_tools() {
  composer_in_project require "kanopi/wp-core-installer:*" "fake/wordpress-core:*" "acme/tools:$1"
}

@test "package:path copies one nested file out of a normally installed package" {
  tools_release 1.0.0
  configure_tools '"acme/tools:includes/object-cache.php": "public/wp-content/"'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  [ "$(cat "${PROJ}/public/wp-content/object-cache.php")" = "<?php // drop-in 1.0.0" ]
  [ -f "${PROJ}/public/wp-content/plugins/tools/tools.php" ]
  [ -f "${PROJ}/public/wp-content/plugins/tools/includes/object-cache.php" ]

  block="$(sed -n '/packages:begin/,/packages:end/p' "${PROJ}/.gitignore")"
  grep -qx '/public/wp-content/object-cache.php' <<<"$block"
  grep -qx '/public/wp-content/plugins/tools/'   <<<"$block"
  ! grep -qx '/public/wp-content/' <<<"$block" || false
}

@test "package:path copies a file and a folder, keeping their names" {
  tools_release 1.0.0
  configure_tools '"acme/tools:loader.php": "public/wp-content/mu-plugins/", "acme/tools:assets": "public/wp-content/mu-plugins/"'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  [ -f "${MU}/loader.php" ]
  [ -f "${MU}/assets/app.css" ]
  [ -f "${MU}/assets/old.css" ]
  [ -f "${MU}/my-plugin.php" ]
  grep -qx '/public/wp-content/mu-plugins/loader.php' "${PROJ}/.gitignore"
  grep -qx '/public/wp-content/mu-plugins/assets/'   "${PROJ}/.gitignore"
}

@test "package:path follows package updates and drops files the package stops shipping" {
  tools_release 1.0.0
  tools_release 2.0.0 v2
  configure_tools '"acme/tools:loader.php": "public/wp-content/mu-plugins/", "acme/tools:assets": "public/wp-content/mu-plugins/"'
  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  run install_tools 2.0.0
  [ "$status" -eq 0 ]

  [ "$(cat "${MU}/loader.php")" = "<?php // loader 2.0.0" ]
  [ -f "${MU}/assets/app.css" ]
  [ ! -e "${MU}/assets/old.css" ]
  [ -f "${MU}/my-plugin.php" ]
}

@test "dropping one entry removes only what that entry placed" {
  tools_release 1.0.0
  configure_tools '"acme/tools:loader.php": "public/wp-content/mu-plugins/", "acme/tools:includes/object-cache.php": "public/wp-content/"'
  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  configure_tools '"acme/tools:includes/object-cache.php": "public/wp-content/"'
  run composer_in_project install
  [ "$status" -eq 0 ]

  [ ! -e "${MU}/loader.php" ]
  [ -f "${PROJ}/public/wp-content/object-cache.php" ]
  [ -f "${MU}/my-plugin.php" ]
  ! grep -qx '/public/wp-content/mu-plugins/loader.php' "${PROJ}/.gitignore" || false
}

@test "a destination without a trailing slash renames the file" {
  tools_release 1.0.0
  configure_tools '"acme/tools:loader.php": "public/wp-content/mu-plugins/000-acme-tools.php"'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  [ "$(cat "${MU}/000-acme-tools.php")" = "<?php // loader 1.0.0" ]
  [ ! -e "${MU}/loader.php" ]
  grep -qx '/public/wp-content/mu-plugins/000-acme-tools.php' "${PROJ}/.gitignore"
}

@test "a folder can be renamed, and destinations can be anywhere in the project" {
  tools_release 1.0.0
  tools_release 2.0.0 v2
  configure_tools '"acme/tools:assets": "public/app/acme-assets", "acme/tools:loader.php": "config/acme/loader.php"'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ -f "${PROJ}/public/app/acme-assets/app.css" ]
  [ -f "${PROJ}/public/app/acme-assets/old.css" ]
  [ ! -e "${PROJ}/public/app/assets" ]
  [ "$(cat "${PROJ}/config/acme/loader.php")" = "<?php // loader 1.0.0" ]
  grep -qx '/public/app/acme-assets/' "${PROJ}/.gitignore"
  grep -qx '/config/acme/loader.php'  "${PROJ}/.gitignore"

  run install_tools 2.0.0
  [ "$status" -eq 0 ]
  [ ! -e "${PROJ}/public/app/acme-assets/old.css" ]
  [ "$(cat "${PROJ}/config/acme/loader.php")" = "<?php // loader 2.0.0" ]

  run composer_in_project remove acme/tools
  [ "$status" -eq 0 ]
  [ ! -e "${PROJ}/public/app/acme-assets" ]
  [ ! -e "${PROJ}/config/acme/loader.php" ]
}

@test "a path the package doesn't contain warns and copies nothing" {
  tools_release 1.0.0
  configure_tools '"acme/tools:nope.php": "public/wp-content/mu-plugins/"'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [[ "$output" == *'acme/tools does not contain "nope.php"'* ]] || false
  [ ! -e "${MU}/nope.php" ]
}

# ── modes ───────────────────────────────────────────────────────────────────

@test "if-missing copies once, then never updates, removes or gitignores it" {
  tools_release 1.0.0
  tools_release 2.0.0 v2
  configure_tools '"acme/tools:loader.php": {"to": "config/loader.php", "mode": "if-missing"}'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ "$(cat "${PROJ}/config/loader.php")" = "<?php // loader 1.0.0" ]
  ! grep -q 'config/loader.php' "${PROJ}/.gitignore" || false

  run install_tools 2.0.0
  [ "$status" -eq 0 ]
  [ "$(cat "${PROJ}/config/loader.php")" = "<?php // loader 1.0.0" ]

  run composer_in_project remove acme/tools
  [ "$status" -eq 0 ]
  [ -f "${PROJ}/config/loader.php" ]
}

@test "append keeps one marked section at the end of the file, updated in place" {
  tools_release 1.0.0
  tools_release 2.0.0 v2
  mkdir -p "${PROJ}/public"
  printf '# my rules\nRewriteEngine On\n' > "${PROJ}/public/.htaccess"
  configure_tools '"acme/tools:rules.conf": {"to": "[web-root]/.htaccess", "mode": "append"}'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  head -2 "${PROJ}/public/.htaccess" | grep -qx 'RewriteEngine On'
  grep -qx 'RewriteRule ^tools-1.0.0$ /tools \[L\]' "${PROJ}/public/.htaccess"
  [ "$(tail -1 "${PROJ}/public/.htaccess")" = "# END acme/tools:rules.conf" ]
  ! grep -q '.htaccess' "${PROJ}/.gitignore" || false

  run composer_in_project install
  [ "$status" -eq 0 ]
  [ "$(grep -c 'BEGIN acme/tools:rules.conf' "${PROJ}/public/.htaccess")" -eq 1 ]

  run install_tools 2.0.0
  [ "$status" -eq 0 ]
  grep -qx 'RewriteRule ^tools-2.0.0$ /tools \[L\]' "${PROJ}/public/.htaccess"
  ! grep -q 'tools-1.0.0' "${PROJ}/public/.htaccess" || false
  [ "$(grep -c 'BEGIN acme/tools:rules.conf' "${PROJ}/public/.htaccess")" -eq 1 ]
}

@test "removing an append entry strips only its section" {
  tools_release 1.0.0
  mkdir -p "${PROJ}/public"
  printf '# my rules\nRewriteEngine On\n' > "${PROJ}/public/.htaccess"
  configure_tools '"acme/tools:rules.conf": {"to": "[web-root]/.htaccess", "mode": "append"}'
  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  run composer_in_project remove acme/tools
  [ "$status" -eq 0 ]

  [ "$(cat "${PROJ}/public/.htaccess")" = "$(printf '# my rules\nRewriteEngine On')" ]
}

@test "prepend puts the section first, and a missing destination is created" {
  tools_release 1.0.0
  configure_tools '"acme/tools:rules.conf": {"to": "[web-root]/.htaccess", "mode": "prepend"}, "acme/tools:loader.php": {"to": "config/extra.php", "mode": "append", "comment": "//"}'
  mkdir -p "${PROJ}/public"
  printf 'RewriteEngine On\n' > "${PROJ}/public/.htaccess"

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ "$(head -1 "${PROJ}/public/.htaccess")" = "# BEGIN acme/tools:rules.conf (managed by kanopi/wp-core-installer copy-to)" ]
  [ "$(tail -1 "${PROJ}/public/.htaccess")" = "RewriteEngine On" ]
  [ "$(head -1 "${PROJ}/config/extra.php")" = "// BEGIN acme/tools:loader.php (managed by kanopi/wp-core-installer copy-to)" ]

  run composer_in_project remove acme/tools
  [ "$status" -eq 0 ]
  [ ! -e "${PROJ}/config/extra.php" ]
}

@test "append and prepend refuse a folder source" {
  tools_release 1.0.0
  configure_tools '"acme/tools:assets": {"to": "[web-root]/.htaccess", "mode": "append"}'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [[ "$output" == *'mode "append" works on a single file, and "assets" is not a file'* ]] || false
  # (core's own .htaccess exists; it must not have gained a section)
  ! grep -q 'BEGIN acme/tools:assets' "${PROJ}/public/.htaccess" || false
}

@test "gitignore can be switched off for an overwrite entry" {
  tools_release 1.0.0
  configure_tools '"acme/tools:loader.php": {"to": "[mu-plugins]/", "gitignore": false}'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ -f "${MU}/loader.php" ]
  ! grep -q 'mu-plugins/loader.php' "${PROJ}/.gitignore" || false
}

@test "changing an entry's destination moves it" {
  tools_release 1.0.0
  configure_tools '"acme/tools:loader.php": "config/a.php"'
  run install_tools 1.0.0
  [ "$status" -eq 0 ]

  configure_tools '"acme/tools:loader.php": "config/b.php"'
  run composer_in_project install
  [ "$status" -eq 0 ]

  [ ! -e "${PROJ}/config/a.php" ]
  [ -f "${PROJ}/config/b.php" ]
}

# ── entries declared by packages ────────────────────────────────────────────

TOOLS_DECLARES='{"wp-core-installer": {"copy-to": {"loader.php": "[mu-plugins]/", "includes/object-cache.php": "[wp-content]/"}}}'

@test "a package's own copy-to is ignored unless the project allows it" {
  tools_release 1.0.0 "" "$TOOLS_DECLARES"
  configure_tools ''

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ ! -e "${MU}/loader.php" ]
  [ ! -e "${PROJ}/public/wp-content/object-cache.php" ]
}

@test "an allowed package's own copy-to entries are applied" {
  tools_release 1.0.0 "" "$TOOLS_DECLARES"
  configure_tools '' '"copy-to-allowed-packages": ["acme/tools"]'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ -f "${MU}/loader.php" ]
  [ -f "${PROJ}/public/wp-content/object-cache.php" ]
  grep -qx '/public/wp-content/mu-plugins/loader.php' "${PROJ}/.gitignore"

  run composer_in_project remove acme/tools
  [ "$status" -eq 0 ]
  [ ! -e "${MU}/loader.php" ]
  [ ! -e "${PROJ}/public/wp-content/object-cache.php" ]
}

@test "the project can override or switch off a package's entry" {
  tools_release 1.0.0 "" "$TOOLS_DECLARES"
  configure_tools '"acme/tools:loader.php": "config/loader.php", "acme/tools:includes/object-cache.php": false' '"copy-to-allowed-packages": ["acme/tools"]'

  run install_tools 1.0.0
  [ "$status" -eq 0 ]
  [ -f "${PROJ}/config/loader.php" ]
  [ ! -e "${MU}/loader.php" ]
  [ ! -e "${PROJ}/public/wp-content/object-cache.php" ]
}

@test "a package's destinations must use a placeholder and stay inside the project" {
  tools_release 1.0.0 "" '{"wp-core-installer": {"copy-to": {"loader.php": "public/wp-content/mu-plugins/"}}}'
  configure_tools '' '"copy-to-allowed-packages": ["acme/tools"]'
  run install_tools 1.0.0
  [ "$status" -ne 0 ]
  [[ "$output" == *'must start with a placeholder such as [mu-plugins]/'* ]] || false

  rm -rf "${PROJ}/vendor" "${PROJ}/public/wp-content/mu-plugins/vendor" "${PROJ}/composer.lock"
  tools_release 1.0.0 "" '{"wp-core-installer": {"copy-to": {"loader.php": "[project-root]/../../outside.php"}}}'
  run install_tools 1.0.0
  [ "$status" -ne 0 ]
  [[ "$output" == *'must stay inside the project'* ]] || false
}
