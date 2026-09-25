#!/usr/bin/env bats
#
# Opt-in deploy of bundled themes/plugins shipped with core (#21).

load 'helpers'

setup() {
  setup_project
  mkdir -p "${CORE}/wp-content/plugins/akismet" "${CORE}/wp-content/themes/twentytwentyfour"
  printf '<?php // akismet\n' > "${CORE}/wp-content/plugins/akismet/akismet.php"
  printf '<?php // hello\n'   > "${CORE}/wp-content/plugins/hello.php"
  printf 'body{}\n'           > "${CORE}/wp-content/themes/twentytwentyfour/style.css"
}

bundle() {
  set_extra "{\"wordpress-install-dir\": \"web\", \"wp-core-installer\": {\"deploy-bundled\": $1}}"
}

@test "nothing bundled is deployed by default" {
  run install_core
  [ "$status" -eq 0 ]

  [ ! -e "${PROJ}/web/wp-content/themes/twentytwentyfive" ]
  [ ! -e "${PROJ}/web/wp-content/plugins/akismet" ]
  [ ! -e "${PROJ}/web/wp-content/plugins/hello.php" ]
}

@test "listed themes and plugins are deployed and gitignored as whole units" {
  bundle '{"themes": ["twentytwentyfive"], "plugins": ["akismet", "hello.php"]}'

  run install_core
  [ "$status" -eq 0 ]

  [ -f "${PROJ}/web/wp-content/themes/twentytwentyfive/style.css" ]
  [ -f "${PROJ}/web/wp-content/plugins/akismet/akismet.php" ]
  [ -f "${PROJ}/web/wp-content/plugins/hello.php" ]
  [ ! -e "${PROJ}/web/wp-content/themes/twentytwentyfour" ]

  block="$(sed -n '/core:begin/,/core:end/p' "${PROJ}/.gitignore")"
  grep -qx '/web/wp-content/themes/twentytwentyfive/' <<<"$block"
  grep -qx '/web/wp-content/plugins/akismet/'         <<<"$block"
  grep -qx '/web/wp-content/plugins/hello.php'        <<<"$block"
  ! grep -q 'style.css' <<<"$block" || false
}

@test "dropping an item from the list removes what was deployed, but not the project's own files" {
  bundle '{"themes": ["twentytwentyfive"], "plugins": ["akismet"]}'
  run install_core
  [ "$status" -eq 0 ]
  printf 'my tweak\n' > "${PROJ}/web/wp-content/themes/twentytwentyfive/custom.css"

  bundle '{"plugins": ["akismet"]}'
  run composer_in_project install
  [ "$status" -eq 0 ]

  [ ! -e "${PROJ}/web/wp-content/themes/twentytwentyfive/style.css" ]
  [ -f "${PROJ}/web/wp-content/themes/twentytwentyfive/custom.css" ]
  [ -f "${PROJ}/web/wp-content/plugins/akismet/akismet.php" ]
  ! grep -q 'twentytwentyfive' "${PROJ}/.gitignore" || false
}

@test "the project's own protected-paths win over deploy-bundled" {
  set_extra '{"wordpress-install-dir": "web", "wp-core-installer": {"deploy-bundled": {"plugins": ["akismet"]}, "protected-paths": ["wp-content/plugins/akismet"]}}'

  run install_core
  [ "$status" -eq 0 ]

  [ ! -e "${PROJ}/web/wp-content/plugins/akismet" ]
}

@test "invalid deploy-bundled names are rejected" {
  bundle '{"themes": ["../../etc"]}'

  run install_core
  [ "$status" -ne 0 ]
  [[ "$output" == *"must be a single theme or plugin name"* ]] || false
}
