#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
REMOTE="$TMP/origin.git"; PUBLISHER="$TMP/publisher"; CHECKOUT="$TMP/checkout"
CONFIG="$TMP/updater.json"; HELPER="$TMP/updater"

git init --bare --initial-branch=main "$REMOTE" >/dev/null
git init --initial-branch=main "$PUBLISHER" >/dev/null
git -C "$PUBLISHER" config user.email test@example.invalid
git -C "$PUBLISHER" config user.name 'ArcadeCloud test'
mkdir -p "$PUBLISHER/drive/bin"
cp "$ROOT/drive/bin/arcadecloud-drive-updater.php" "$PUBLISHER/drive/bin/arcadecloud-drive-updater.php"
echo A > "$PUBLISHER/version"; git -C "$PUBLISHER" add .; git -C "$PUBLISHER" commit -m A >/dev/null
git -C "$PUBLISHER" remote add origin "$REMOTE"; git -C "$PUBLISHER" push -u origin main >/dev/null
git clone "$REMOTE" "$CHECKOUT" >/dev/null
sed "s#private const CONFIG = '/etc/arcadecloud-drive/updater.json';#private const CONFIG = '$CONFIG';#" "$ROOT/drive/bin/arcadecloud-drive-updater.php" > "$HELPER"
chmod 0755 "$HELPER"
printf '{"repo_root":"%s","repo_user":"%s","php_user":"%s"}\n' "$CHECKOUT" "$(id -un)" "$(id -un)" > "$CONFIG"
git -C "$CHECKOUT" remote set-url origin git@github.com:jimmybackend/s3.git
cat > "$TMP/git-ssh" <<SH
#!/usr/bin/env bash
exec /usr/bin/git-upload-pack '$REMOTE'
SH
chmod 0755 "$TMP/git-ssh"
export GIT_SSH_COMMAND="$TMP/git-ssh"

echo B > "$PUBLISHER/version"; git -C "$PUBLISHER" add version; git -C "$PUBLISHER" commit -m B >/dev/null; git -C "$PUBLISHER" push origin main >/dev/null
state="$($HELPER check)"
php -r '$s=json_decode($argv[1],true); if (!$s["update_available"] || $s["behind"] !== 1 || $s["ahead"] !== 0 || $s["local_commit"] === $s["remote_commit"]) exit(1);' "$state"
echo 'OK: check hace fetch y detecta A -> B'
applied="$($HELPER apply)"
php -r '$s=json_decode($argv[1],true); if (!$s["updated"] || $s["behind"] !== 0 || $s["update_available"]) exit(1);' "$applied"
echo 'OK: apply avanza sólo por fast-forward'

echo dirty >> "$CHECKOUT/version"; state="$($HELPER check)"
php -r '$s=json_decode($argv[1],true); if (!$s["dirty"]) exit(1);' "$state"
git -C "$CHECKOUT" checkout -- version; echo 'OK: check diferencia checkout dirty'
git -C "$CHECKOUT" checkout -b feature >/dev/null; state="$($HELPER check)"
php -r '$s=json_decode($argv[1],true); if ($s["branch"] !== "feature") exit(1);' "$state"
git -C "$CHECKOUT" checkout main >/dev/null; echo 'OK: check informa rama distinta de main'

git -C "$CHECKOUT" config user.email test@example.invalid
git -C "$CHECKOUT" config user.name 'ArcadeCloud test'
echo local > "$CHECKOUT/local"; git -C "$CHECKOUT" add local; git -C "$CHECKOUT" commit -m local >/dev/null
state="$($HELPER check)"
php -r '$s=json_decode($argv[1],true); if ($s["ahead"] !== 1 || $s["can_apply"]) exit(1);' "$state"
echo 'OK: check diferencia checkout ahead y bloquea apply'

git -C "$CHECKOUT" remote set-url origin https://example.invalid/not-allowed.git
if "$HELPER" check >"$TMP/wrong.out" 2>"$TMP/wrong.err"; then exit 1; fi
grep -q 'no corresponde al repositorio oficial' "$TMP/wrong.err"; echo 'OK: check rechaza remoto no oficial'
git -C "$CHECKOUT" remote set-url origin git@github.com:jimmybackend/s3.git
cat > "$TMP/git-ssh" <<'SH'
#!/usr/bin/env bash
exit 42
SH
if "$HELPER" check >"$TMP/fetch.out" 2>"$TMP/fetch.err"; then exit 1; fi
test -s "$TMP/fetch.err"; echo 'OK: fetch fallido produce error y no falso actualizado'
