#!/usr/bin/env bats
#
# Guards the suite itself. In bats, a negated command (`! grep …`) never fails
# a test unless it is the test's last line — on any bash — and `[[ … ]]` has
# the same problem on bash < 4.1 (macOS's /bin/bash is 3.2). Both must be
# written as `… || false` to be enforced everywhere.

@test "every ! and [[ ]] assertion ends with || false" {
  run bash -c "grep -nE '^[[:space:]]+(\[\[ |! )' '${BATS_TEST_DIRNAME}'/*.bats | grep -v '|| false[[:space:]]*\$' | grep -v 'zz-suite-hygiene.bats'"
  if [ -n "$output" ]; then
    echo "Unenforced assertions (append ' || false'):"
    echo "$output"
    return 1
  fi
}
