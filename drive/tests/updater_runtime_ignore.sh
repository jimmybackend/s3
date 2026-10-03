#!/usr/bin/env bash
set -euo pipefail
repo="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
git init -q "$fixture"
cp "$repo/.gitignore" "$fixture/.gitignore"
git -C "$fixture" add .gitignore
git -C "$fixture" -c user.name=Fixture -c user.email=fixture@example.invalid commit -qm initial
mkdir -p "$fixture/vendor/example" "$fixture/drive/upload/storage/state"
echo dependency > "$fixture/vendor/example/dependency.php"
echo fixture > "$fixture/drive/upload/storage/state/upload.json"
echo fixture > "$fixture/drive/upload/storage/state/.lock-ab"
test -z "$(git -C "$fixture" status --porcelain)"
echo 'OK: dependencies and active upload state do not dirty checkout'
echo hotfix > "$fixture/hotfix.php"
git -C "$fixture" status --porcelain | grep -q 'hotfix.php'
git -C "$fixture" -c user.name=Fixture -c user.email=fixture@example.invalid stash push --include-untracked -qm fixture
test -f "$fixture/drive/upload/storage/state/upload.json"
test -f "$fixture/drive/upload/storage/state/.lock-ab"
test -f "$fixture/vendor/example/dependency.php"
test ! -f "$fixture/hotfix.php"
echo 'OK: include-untracked stash retains active upload state and dependencies'
