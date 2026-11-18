# XiangGraph: judge quickstart

XiangGraph makes coordination rules executable where autonomous agents' work overlaps. In the refund example, a design decision changes the rules for two agents. Both have to acknowledge the change before implementation can proceed, while the third agent's finished guide stays intact.

## Start with the replay (no API key)

Open [the English replay](showcase/film-en/index.html) from your checkout in a desktop browser. Choose **Reader view**, then **Design disagreement**. Step through **Round 1**, **Round 2**, **Escalate**, **Patch**, **ACK A**, and **ACK B**. At Patch, the change covers W1 and W2. At ACK A, the second confirmation is still pending. The documentation task stays at revision 1 throughout. Open **Recorded receipt** to inspect the selected event.

These controls inspect records of a completed run. They do not start agents or change any state.

## Run the checks and MCP bridge

Use PHP 8.5 CLI with Fileinfo and Iconv, Composer, and Bash. The lockfile pins Symfony AI 1.0.0-BETA1 and the official PHP MCP SDK 0.9.0. From the repository root:

```bash
composer install --no-interaction --prefer-dist
bash scripts/demo/run.sh --no-serve --brief
```

The replay check should reconcile all three frozen event chains. To run actual local PHP MCP SDK calls from Symfony AI without a model key, use Linux or WSL:

```bash
php packages/xianggraph-symfony/bin/bridge-probe.php --run=p3-real-03 --expect-block=W1
```

The tool call `xg_explain_block` should report the recorded `RESOURCE_BUSY` denial. The bridge reads records over stdio and does not invoke a model. The Linux run passed six probe cases, including two expected refusals. Native Windows was checked for replay and compiler output; its stdio probe hung and is not a supported MCP path for this cut. The model query in the film is a separate historical run on Symfony AI 0.15.0.

## Recompute what changes when a route is added

In a disposable checkout, run:

```bash
bash experiments/g1/run.sh
```

This regenerates the compiler outputs under `experiments/g1/`. In both rows for `g1-add-route-file`, the new route matches an existing import pattern. The first result is `WAIT` while the affected checks are stale. The second is `ALLOW` for the current snapshot after the local consumer check. The prototype does not publish the change.
