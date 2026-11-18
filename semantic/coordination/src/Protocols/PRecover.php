<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolEffect;
use XiangGraph\Coord\State;

/**
 * P-RECOVER: failure, unknown external effect and reconciliation.
 *
 * A timed-out external effect is not a reason to mint a new operation key.
 * The protocol holds the affected scope and asks for reconciliation using the
 * original key and payload fingerprint.
 */
final class PRecover extends AbstractProtocol
{
    public function type(): string
    {
        return 'P-RECOVER';
    }

    public function signals(): array
    {
        return ['EXTERNAL_EFFECT_UNKNOWN', 'RETRY_REQUESTED', 'RECONCILED', 'RECONCILIATION_FAILED'];
    }

    public function evaluate(Plan $plan, array $protocol, array $event, State $state): ProtocolEffect
    {
        $effect = ProtocolEffect::for($this->type());
        $scope = $this->scope($event, $plan);
        $type = (string) ($event['type'] ?? '');
        $source = $this->type() . ':' . (string) ($protocol['version'] ?? 'v0.1');

        if ($scope === []) {
            return $effect;
        }

        if ($type === 'EXTERNAL_EFFECT_UNKNOWN') {
            $opKey = (string) ($event['op_key'] ?? 'unknown-op');
            $rootCause = 'effect:' . $opKey;
            $reasonId = 'effect:' . $opKey;
            $effect->hold($scope, 'EXTERNAL_EFFECT_UNKNOWN:' . $opKey, $source, 'PUBLISH', 'RECONCILED', $reasonId, 'RECEIVER', 'RECONCILED', $opKey, $rootCause);
            $effect->obligation('RECONCILE', $scope, $source, 'external effect is unknown; reconcile before any retry', $rootCause, $reasonId);
            $effect->action('RECONCILE', $scope, 'LOCAL_DETERMINISTIC', 'query the receiver with the original operation key and payload fingerprint', [
                'op_key' => $opKey,
                'payload_fingerprint' => $event['payload_fingerprint'] ?? null,
                'new_key_allowed' => false,
            ]);
            $effect->notes[] = 'unknown external effect is held, not silently retried';

            return $effect;
        }

        if ($type === 'RETRY_REQUESTED') {
            $requestedKey = (string) ($event['op_key'] ?? '');
            $heldKey = $this->heldUnknownEffectKey($state, $scope);
            if ($heldKey === null) {
                $effect->notes[] = 'no unknown effect is currently held; retry request is not a recovery decision';

                return $effect;
            }
            if ($requestedKey !== $heldKey) {
                $effect->deny('DENY_NEW_KEY_BLIND_RETRY', $scope, 'a different operation key is a new effect, not a retry');
                $effect->notes[] = 'blind retry with a new operation key is denied';

                return $effect;
            }
            $expectedPayload = (string) ($event['expected_payload_fingerprint'] ?? '');
            $actualPayload = (string) ($event['payload_fingerprint'] ?? '');
            if ($expectedPayload !== '' && $actualPayload !== '' && !hash_equals($expectedPayload, $actualPayload)) {
                $effect->deny('DENY_PAYLOAD_MISMATCH', $scope, 'the same operation key with a different payload is a different operation');
                $effect->notes[] = 'same-key retry with a different payload is denied';

                return $effect;
            }
            $effect->action('RETRY_SAME_KEY', $scope, 'LOCAL_DETERMINISTIC', 'same operation key and payload may be retried conditionally');

            return $effect;
        }

        if ($type === 'RECONCILED') {
            $opKey = (string) ($event['op_key'] ?? '');
            $effect->action('EFFECT_CONFIRMED', $scope, 'LOCAL_DETERMINISTIC', 'the receiver confirmed the effect; release the hold', [
                'op_key' => $opKey,
                'reconciled' => true,
            ]);
            $effect->notes[] = 'reconciliation completed without a blind retry';

            return $effect;
        }

        if ($type === 'RECONCILIATION_FAILED') {
            $opKey = (string) ($event['op_key'] ?? '');
            $effect->escalate('RECOVERY_STRATEGY_EXHAUSTED', $scope, 'effect:' . $opKey, [$event['event_id'] ?? '']);
            $effect->notes[] = 'reconciliation failed; dangerous action stays held';

            return $effect;
        }

        return $effect;
    }

    /**
     * @param list<string> $scope
     */
    private function heldUnknownEffectKey(State $state, array $scope): ?string
    {
        foreach ($state->holds as $hold) {
            if (($hold['status'] ?? '') !== 'ACTIVE' || ($hold['purpose'] ?? '') !== 'PUBLISH') {
                continue;
            }
            $reason = (string) ($hold['reason'] ?? '');
            if (!str_starts_with($reason, 'EXTERNAL_EFFECT_UNKNOWN:')) {
                continue;
            }
            if (State::intersects($hold['scope'], $scope)) {
                return substr($reason, \strlen('EXTERNAL_EFFECT_UNKNOWN:'));
            }
        }

        return null;
    }
}
