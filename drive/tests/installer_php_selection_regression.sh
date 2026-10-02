#!/usr/bin/env bash
set -euo pipefail

# Load only the actual selection function, never the installer entrypoint.
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
function_source="$(sed -n '/^detect_php_package_family() {$/,/^}$/p' "$repo/drive/bin/install_arcadecloud_server.sh")"
[[ -n "$function_source" ]] || { echo 'Selection function missing' >&2; exit 1; }
eval "$function_source"

command_exists() { [[ "$1" == php && "$fixture_installed" != none ]]; }
php() { [[ "$fixture_version" -ge 80401 ]]; }
rpm() { printf '%s\n' "$fixture_installed"; }
package_available() { [[ " $fixture_packages " == *" $1 "* ]]; }
fail() { echo "$*" >&2; exit 1; }
# Forbidden side effects: the selection tests cannot install or operate services.
dnf() { echo 'Unexpected package mutation' >&2; exit 99; }
systemctl() { echo 'Unexpected service operation' >&2; exit 99; }

assert_selection() {
  local expected="$1" actual
  if ! actual="$(detect_php_package_family 2>/dev/null)"; then actual=blocked; fi
  [[ "$actual" == "$expected" ]] || { echo "Expected $expected, got $actual" >&2; exit 1; }
  echo "OK: $fixture_installed ($fixture_version) -> $expected"
}
fixture_installed=none fixture_version=0 fixture_packages='php8.5 php8.5-fpm php8.4 php8.4-fpm'
assert_selection php8.5
fixture_packages='php8.4 php8.4-fpm'
assert_selection php8.4
fixture_packages='php8.3 php8.3-fpm php8.2 php8.2-fpm'
assert_selection blocked
fixture_installed=php8.3-cli fixture_version=80399 fixture_packages='php8.5 php8.5-fpm'
assert_selection blocked
fixture_installed=php8.4-cli fixture_version=80400 fixture_packages='php8.4 php8.4-fpm php8.5 php8.5-fpm'
assert_selection blocked
fixture_version=80401
assert_selection php8.4
fixture_installed=php8.5-cli fixture_version=80500
assert_selection php8.5
fixture_installed=custom-php
assert_selection blocked
