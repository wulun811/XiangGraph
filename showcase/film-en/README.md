# English coordination replay

An English, read-only UI for the sealed `p3-real-03`, `p4-real-01` and `p6-pair-01r3-xg` runs. Open `index.html` from a local static server or directly from disk; it reads `../v2/projection.js` and never calls a model or changes recorded data.

The run button, checkpoint buttons, next-event button and receipt disclosure are functional. They inspect recorded actions; selecting ACK does not issue a new acknowledgement. This is not a live task-control UI.

P4 keeps the verifier at NOT RUN. P6 preserves the local W1/W2 patch scope, W3's separate completion evidence, publication_committed=false, and failure/repair receipts. Browser checks cover every checkpoint and explicitly check these boundaries.

Created for the English film bridge from the concept animation into real UI interaction. The screen recording shows actual clicks in this working page, with recorded mode continuously visible.

The current identity mark is `assets/xianggraph-junction.svg`: a four-way junction with connected terminal nodes and a central coordination node. It replaces the earlier radial symbol in this UI and in the full film.

The Reader view link opens `index.html?film=1`. This uses the same source data and controls, with larger roles, matching agent artwork, and concise event summaries. Raw receipts remain available in the disclosure. Clicking the wordmark returns to the standard view. `presentation.js` derives displayed facts from recorded payloads; it does not add backend behavior. `film.css` provides the presentation layout, checked at 1600 × 900.

The evidence is a path-redacted export. See [EVIDENCE.md](../../docs/EVIDENCE.md) for the original and public chain heads and the limits of this replay.
