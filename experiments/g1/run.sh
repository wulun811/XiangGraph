#!/usr/bin/env bash
# Reproduce the G1 no-side-effect compiler vertical slice.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
compiler="$repo/semantic/compiler"
workspace="$compiler/workspaces/symfony-routing-glob"
contract="$compiler/contracts/symfony-routing-glob.json"
out="$here/out"

rm -rf "$out"
mkdir -p "$out"

php "$compiler/tests/run.php" | tee "$here/tests-g1.txt"

for change in 01-add-route-file 02-docs-only 03-glob-rule-zero-match 04-plugin-unknown 05-unmapped-src; do
  php "$compiler/bin/compile.php" \
    "$workspace" "$compiler/changes/$change/change.json" \
    --contract="$contract" \
    --out="$out/$change.json"
done

php "$compiler/consumer/check_routes.php" \
  "$workspace" "$compiler/changes/01-add-route-file/change.json" "$contract" \
  > "$out/check-01-add-route-file.json"

php "$compiler/bin/compile.php" \
  "$workspace" "$compiler/changes/01-add-route-file/change.json" \
  --contract="$contract" \
  --verification="$out/check-01-add-route-file.json" \
  --out="$out/01-add-route-file.verified.json"

php "$compiler/bin/summarize.php" \
  "$out/01-add-route-file.json" \
  "$out/01-add-route-file.verified.json" \
  "$out/02-docs-only.json" \
  "$out/03-glob-rule-zero-match.json" \
  "$out/04-plugin-unknown.json" \
  "$out/05-unmapped-src.json" \
  | tee "$here/run_all.txt"

echo
sha256sum "$out"/*.json "$here/tests-g1.txt" | sed "s#$repo/##" | tee "$here/hashes.txt"
