# Evidence and boundaries

The P3, P4 and P6 pages display events from earlier model runs. This repository contains a **redacted export** of those records: host-specific absolute path prefixes were replaced before publishing, then affected event chains and the presentation projection were rebuilt. The original sealed records were not changed. The original and public chain heads are listed in [public-redaction-heads.json](../experiments/demo01/public-redaction-heads.json). The public chain verifies this export; it is not the original chain head or independent verification.

P3's two RESOURCE_BUSY receipts are refused lease requests. P4's verifier did not run; the host oracle is separate. P6's patch changes the coordination rules of W1/W2, and both ACKs precede implementation; W3's earlier completion is separately recorded. An ACK does not establish publication or a full agent completion.

The routing example is a bounded pure compiler prototype on a controlled Symfony-style snapshot. Its ALLOW result denotes current snapshot eligibility, not a committed publication. The Symfony AI / MCP bridge is a local read-only interface; the validated 1.0.0-BETA1 probe uses no model. The historical model query shown in the film used Symfony AI 0.15. The full write-authorized MCP workflow and production runtime are future work.
