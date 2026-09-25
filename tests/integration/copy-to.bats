#!/usr/bin/env bats
#
# copy-to: packages that must sit in a shared folder (#46), modelled on the
# Kinsta MU plugin (kinsta-mu-plugins.php + kinsta-mu-plugins/ in mu-plugins/).

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
    '"copy-to": {"kinsta/kinsta-mu-plugins": "wp-content/mu-plugins"}'
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

@test "an existing file that copy-to did not place is never overwritten" {
  kinsta_release 3.5.0
  use_copy_to
  # e.g. an older, previously committed copy of the Kinsta MU plugin
  printf '<?php // committed older copy\n' > "${MU}/kinsta-mu-plugins.php"

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]

  [ "$(cat "${MU}/kinsta-mu-plugins.php")" = "<?php // committed older copy" ]
  [[ "$output" == *"were not placed by it; left untouched"* ]] || false
  [[ "$output" == *"kinsta-mu-plugins.php"* ]] || false
  [ -f "${MU}/kinsta-mu-plugins/lib.php" ]
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
    '"copy-to": {"kinsta/kinsta-mu-plugins": "wp-content/mu-plugins"}'

  run install_kinsta 3.5.0
  [ "$status" -eq 0 ]

  [[ "$output" == *"would be copied over its own install folder"* ]] || false
  [ ! -e "${MU}/kinsta-mu-plugins.php" ]
}

@test "an invalid copy-to setting fails clearly" {
  set_extra '{"wordpress-install-dir": "public", "wp-core-installer": {"copy-to": {"not a package": "wp-content"}}}'

  run install_core
  [ "$status" -ne 0 ]
  [[ "$output" == *'copy-to keys must be package names like "vendor/package"'* ]] || false
}
