#!/usr/bin/env bash
#
# run.sh - one-command XiangGraph demo.
#
#   ./scripts/demo/run.sh                # REPLAY: verify frozen runs, print the
#                                        # execution trace, serve the showcase
#   ./scripts/demo/run.sh --no-serve     # same, no HTTP server (CI friendly)
#   ./scripts/demo/run.sh --brief        # one block per run instead of every event
#   ./scripts/demo/run.sh --port=8090    # change the showcase port
#   ./scripts/demo/run.sh --v1           # serve the legacy showcase/v1.2 page
#   ./scripts/demo/run.sh --live         # run one real model query (needs the
#                                        # API key env vars; not the default)
#
# REPLAY mode never calls a model and never writes into a frozen run. It reads
# experiments/demo01/runs/*, recomputes the event hash chain, and prints the
# recorded events. The showcase is static HTML fed by the same frozen data.
# The default showcase is v2 (Observatory); its projection.js is committed and
# regenerate with: php packages/xianggraph-showcase/bin/project.php
#
# This repo's demo is file-based. It does not start SQLite, a worker pool, or a
# long-running MCP server; saying otherwise would be false.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

PORT=8080
SERVE=1
BRIEF=0
LIVE=0
V1=0

for arg in "$@"; do
  case "$arg" in
    --no-serve) SERVE=0 ;;
    --brief) BRIEF=1 ;;
    --live) LIVE=1 ;;
    --v1) V1=1 ;;
    --port=*) PORT="${arg#--port=}" ;;
    -h|--help)
      sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "unknown argument: $arg" >&2
      exit 2
      ;;
  esac
done

if ! [[ "$PORT" =~ ^[0-9]+$ ]]; then
  echo "--port must be numeric" >&2
  exit 2
fi

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-replay"
OUTDIR="$ROOT/experiments/demo_replay/$RUN_ID"
mkdir -p "$OUTDIR"

echo "=== XiangGraph demo ==="
echo "mode=REPLAY (no model call)"
echo "time_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "commit=$(git rev-parse --short HEAD 2>/dev/null || echo 'not-a-git-checkout')"
echo "php=$(php -r 'echo PHP_VERSION;')"
echo "run_id=$RUN_ID"
echo

echo "--- verifying frozen runs ---"
RUNS=(p3-real-03 p4-real-01 p6-pair-01r3-xg)
FAILED=0
for run in "${RUNS[@]}"; do
  php -r '
require "vendor/autoload.php";
$run = $argv[1];
$store = new XiangGraph\Mcp\Ledger\LedgerStore("experiments/demo01/runs");
$r = $store->reconcile($run);
printf("%s  %-18s events=%-3d head=%s\n",
    $r["status"] === "MATCH" ? "PASS" : "FAIL",
    $run,
    $r["event_count"],
    substr((string) $r["recomputed_head"], 0, 16));
exit($r["status"] === "MATCH" ? 0 : 1);
' "$run" || FAILED=1
done
if [[ "$FAILED" != "0" ]]; then
  echo "hash chain mismatch; refusing to replay" >&2
  exit 1
fi
echo

echo "--- replaying recorded events ---"
if [[ "$BRIEF" == "1" ]]; then
  php -r '
require "vendor/autoload.php";
$store = new XiangGraph\Mcp\Ledger\LedgerStore("experiments/demo01/runs");
$runs = ["p3-real-03", "p4-real-01", "p6-pair-01r3-xg"];
foreach ($runs as $run) {
    $doc = json_decode(file_get_contents("experiments/demo01/runs/{$run}/events.json"), true);
    $head = $store->reconcile($run)["recomputed_head"];
    $types = array_count_values(array_map(fn ($e) => $e["type"], $doc["events"]));
    printf("%-18s %d events  head=%s\n", $run, count($doc["events"]), substr((string) $head, 0, 16));
    foreach ($types as $type => $n) {
        printf("    %-28s %d\n", $type, $n);
    }
}
' | tee "$OUTDIR/replay.txt"
else
  php -r '
require "vendor/autoload.php";
$store = new XiangGraph\Mcp\Ledger\LedgerStore("experiments/demo01/runs");
$runs = ["p3-real-03", "p4-real-01", "p6-pair-01r3-xg"];
foreach ($runs as $run) {
    $doc = json_decode(file_get_contents("experiments/demo01/runs/{$run}/events.json"), true);
    $head = $store->reconcile($run)["recomputed_head"];
    printf("### %s  head=%s\n", $run, $head);
    foreach ($doc["events"] as $e) {
        printf("%04d  %-28s %-14s %s\n",
            $e["seq"],
            $e["type"],
            $e["principal"] ?? "-",
            implode(",", $e["scope"] ?? []));
    }
    echo "\n";
}
' | tee "$OUTDIR/replay.txt"
fi

REPLAY_COMMIT="$(git rev-parse HEAD 2>/dev/null || true)"
php -r '
require "vendor/autoload.php";
$out = $argv[1];
$store = new XiangGraph\Mcp\Ledger\LedgerStore("experiments/demo01/runs");
$runs = ["p3-real-03", "p4-real-01", "p6-pair-01r3-xg"];
$doc = [
    "schema" => "xg-demo-replay/v0.1",
    "mode" => "REPLAY",
    "generated_at_utc" => gmdate("Y-m-d\\TH:i:s\\Z"),
    "commit" => $argv[2] !== "" ? $argv[2] : null,
    "php" => PHP_VERSION,
    "runs" => [],
    "transcript_sha256" => hash_file("sha256", $out . "/replay.txt"),
    "notes" => [
        "no model call; no write into a frozen run",
        "events are printed from experiments/demo01/runs/*/events.json",
        "hash chains were recomputed before the replay",
    ],
];
foreach ($runs as $run) {
    $d = json_decode(file_get_contents("experiments/demo01/runs/{$run}/events.json"), true);
    $doc["runs"][] = ["id" => $run, "head_hash" => $store->reconcile($run)["recomputed_head"], "event_count" => count($d["events"])];
}
file_put_contents($argv[1] . "/replay.json", json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("replay.json=%s\n", $argv[1] . "/replay.json");
' "$OUTDIR" "$REPLAY_COMMIT"
echo

if [[ "$LIVE" == "1" ]]; then
  echo "--- live model query ---"
  if [[ -z "${XG_MODEL_API_KEY:-}" ]]; then
    echo "BLOCKED_BY_MODEL_ACCESS: XG_MODEL_API_KEY is not set; no request was sent." >&2
    exit 2
  fi
  php packages/xianggraph-symfony/bin/agent-live.php \
    --provider="${XG_MODEL_PROVIDER:-generic}" \
    --model="${XG_MODEL_NAME:?set XG_MODEL_NAME for --live}" \
    --run=p3-real-03 --scope=W1 --max-tokens=1000 --max-tool-calls=4
  echo
fi

SHOWCASE_DIR="showcase/film-en"

if [[ ! -f "$SHOWCASE_DIR/index.html" ]]; then
  echo "missing $SHOWCASE_DIR/index.html" >&2
  exit 1
fi
if [[ ! -f "showcase/v2/projection.js" ]]; then
  echo "missing $SHOWCASE_DIR/projection.js; run: php packages/xianggraph-showcase/bin/project.php" >&2
  exit 1
fi

if [[ "$SERVE" == "1" ]]; then
  echo "--- showcase ($SHOWCASE_DIR) ---"
  echo "http://127.0.0.1:$PORT/showcase/film-en/   (Ctrl+C to stop)"
  exec php -S "127.0.0.1:$PORT" -t .
fi

echo "showcase: open $SHOWCASE_DIR/index.html or run: php -S 127.0.0.1:8080 -t ."
