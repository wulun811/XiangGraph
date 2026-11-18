<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/composition-engine/src/Canonical.php';
require_once __DIR__ . '/ProtocolContract.php';
require_once __DIR__ . '/AuthorityProvider.php';
require_once __DIR__ . '/DecisionRights.php';
require_once __DIR__ . '/Plan.php';
require_once __DIR__ . '/AuthorityFacts.php';
require_once __DIR__ . '/ProtocolEffect.php';
require_once __DIR__ . '/State.php';
require_once __DIR__ . '/Protocols/ProtocolHandler.php';
require_once __DIR__ . '/Protocols/AbstractProtocol.php';
require_once __DIR__ . '/Protocols/PDep.php';
require_once __DIR__ . '/Protocols/PReview.php';
require_once __DIR__ . '/Protocols/PConflict.php';
require_once __DIR__ . '/Protocols/PRecover.php';
require_once __DIR__ . '/Protocols/PEscalate.php';
require_once __DIR__ . '/ProtocolComposer.php';
require_once __DIR__ . '/EscalationRouter.php';
require_once __DIR__ . '/ProtocolPatchValidator.php';
require_once __DIR__ . '/EventReducer.php';
require_once __DIR__ . '/Replay.php';
