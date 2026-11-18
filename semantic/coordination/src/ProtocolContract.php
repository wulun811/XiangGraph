<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

/**
 * Validates the declarative coordination plan. It only checks structure and
 * known protocol types; it never decides whether a business situation is true.
 */
final class ProtocolContract
{
    public const SCHEMA_VERSION = 'xg-coordination-plan/v0.1';

    public const PROTOCOL_TYPES = ['P-DEP', 'P-CONFLICT', 'P-REVIEW', 'P-RECOVER', 'P-ESCALATE'];

    public const REQUIRED_RISK_KEYS = [
        'external_money_transfer',
        'production_write',
        'unknown_effect_retry',
        'max_negotiation_rounds',
        'director_unavailable_default',
        'human_only_options',
    ];

    public const REQUIRED_ESCALATION_KEYS = [
        'director_only_when',
        'do_not_escalate_for',
        'deduplicate_by',
        'response_requires',
        'response_must_pass',
    ];

    /**
     * @param array<string, mixed> $plan
     * @return array{valid: bool, errors: list<string>}
     */
    public static function validate(array $plan): array
    {
        $errors = [];

        if (($plan['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be ' . self::SCHEMA_VERSION;
        }
        if (!\is_string($plan['plan_id'] ?? null) || $plan['plan_id'] === '') {
            $errors[] = 'plan_id is required';
        }
        if (!\is_int($plan['policy_revision'] ?? null) || $plan['policy_revision'] < 1) {
            $errors[] = 'policy_revision must be an integer >= 1';
        }
        if (!\is_string($plan['goal'] ?? null) || $plan['goal'] === '') {
            $errors[] = 'goal is required';
        }

        $works = $plan['works'] ?? null;
        if (!\is_array($works) || $works === []) {
            $errors[] = 'works must be a non-empty list';
            $works = [];
        }
        $ids = [];
        $dependencies = [];
        foreach ($works as $work) {
            if (!\is_array($work) || !\is_string($work['id'] ?? null) || $work['id'] === '') {
                $errors[] = 'each work needs a non-empty id';
                continue;
            }
            if (isset($ids[$work['id']])) {
                $errors[] = 'duplicate work id: ' . $work['id'];
            }
            $ids[$work['id']] = true;
            if (!\is_array($work['owners'] ?? null) || $work['owners'] === []) {
                $errors[] = 'work ' . $work['id'] . ' needs at least one owner';
            }
            $deps = $work['depends_on'] ?? [];
            if (!\is_array($deps)) {
                $errors[] = 'work ' . $work['id'] . ' depends_on must be a list';
                $deps = [];
            }
            foreach ($deps as $dependency) {
                if (!\is_string($dependency) || $dependency === '') {
                    $errors[] = 'work ' . $work['id'] . ' has an invalid dependency';
                    continue;
                }
                if ($dependency === $work['id']) {
                    $errors[] = 'work ' . $work['id'] . ' cannot depend on itself';
                }
                $dependencies[$work['id']][] = $dependency;
            }
        }
        foreach ($dependencies as $workId => $deps) {
            foreach ($deps as $dependency) {
                if (!isset($ids[$dependency])) {
                    $errors[] = 'work ' . $workId . ' depends on unknown work: ' . $dependency;
                }
            }
        }
        if ($errors === [] && self::hasDependencyCycle($dependencies)) {
            $errors[] = 'work dependency graph contains a cycle';
        }

        $protocols = $plan['protocol_composition'] ?? null;
        if (!\is_array($protocols) || $protocols === []) {
            $errors[] = 'protocol_composition must be a non-empty list';
            $protocols = [];
        }
        foreach ($protocols as $protocol) {
            if (!\is_array($protocol)) {
                $errors[] = 'each protocol entry must be an object';
                continue;
            }
            $type = $protocol['protocol'] ?? null;
            if (!\in_array($type, self::PROTOCOL_TYPES, true)) {
                $errors[] = 'unknown protocol type: ' . (string) $type;
            }
            if (!\is_string($protocol['version'] ?? null) || $protocol['version'] === '') {
                $errors[] = 'protocol ' . (string) $type . ' needs a version';
            }
            $scope = $protocol['scope'] ?? null;
            if (!\is_array($scope) || $scope === []) {
                $errors[] = 'protocol ' . (string) $type . ' needs a non-empty scope';
                continue;
            }
            foreach ($scope as $workId) {
                if (!\is_string($workId) || !isset($ids[$workId])) {
                    $errors[] = 'protocol ' . (string) $type . ' references unknown work: ' . (string) $workId;
                }
            }
            if ($type === 'P-CONFLICT' && !\is_int($protocol['max_rounds'] ?? null) && !isset($plan['risk_boundary']['max_negotiation_rounds'])) {
                $errors[] = 'P-CONFLICT needs max_rounds or a risk_boundary.max_negotiation_rounds';
            }
        }

        $authority = $plan['authority'] ?? null;
        if (!\is_array($authority)) {
            $errors[] = 'authority is required';
            $authority = [];
        } else {
            foreach (['director', 'human_authorizer'] as $role) {
                if (!\is_string($authority[$role] ?? null) || $authority[$role] === '') {
                    $errors[] = 'authority.' . $role . ' is required';
                }
            }
            if (isset($authority['trusted_signers']) && (!\is_array($authority['trusted_signers']) || array_filter($authority['trusted_signers'], static fn (mixed $item): bool => !\is_string($item) || $item === '') !== [])) {
                $errors[] = 'authority.trusted_signers must be a list of non-empty strings';
            }
        }

        $decisionRights = $plan['decision_rights'] ?? null;
        if ($decisionRights !== null) {
            if (!\is_array($decisionRights)) {
                $errors[] = 'decision_rights must be an object';
            } else {
                $errors = array_merge($errors, self::decisionRightsErrors($decisionRights, $ids, $authority));
            }
        }

        $risk = $plan['risk_boundary'] ?? null;
        if (!\is_array($risk)) {
            $errors[] = 'risk_boundary is required';
        } else {
            foreach (self::REQUIRED_RISK_KEYS as $key) {
                if (!\array_key_exists($key, $risk)) {
                    $errors[] = 'risk_boundary.' . $key . ' is required';
                }
            }
            if (isset($risk['external_money_transfer']) && $risk['external_money_transfer'] !== 'PROHIBITED') {
                $errors[] = 'stage B must keep external_money_transfer PROHIBITED';
            }
            if (isset($risk['production_write']) && $risk['production_write'] !== 'PROHIBITED') {
                $errors[] = 'stage B must keep production_write PROHIBITED';
            }
        }

        $policy = $plan['escalation_policy'] ?? null;
        if (!\is_array($policy)) {
            $errors[] = 'escalation_policy is required';
        } else {
            foreach (self::REQUIRED_ESCALATION_KEYS as $key) {
                if (!isset($policy[$key]) || !\is_array($policy[$key])) {
                    $errors[] = 'escalation_policy.' . $key . ' must be a list';
                }
            }
            if (isset($policy['director_only_when']) && \in_array('ORDINARY_PROGRESS', $policy['director_only_when'], true)) {
                $errors[] = 'ORDINARY_PROGRESS cannot be a director_only_when reason';
            }
            if (isset($policy['decision_options'])) {
                $errors = array_merge($errors, self::decisionOptionsErrors($policy['decision_options'], $ids));
            }
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Data-layer options for an escalation reason. This is not a new protocol
     * type: it only lets a plan supply task-specific choices to the same
     * EscalationRouter / ProtocolPatchValidator path.
     *
     * @param mixed $configured
     * @param array<string, bool> $workIds
     * @return list<string>
     */
    private static function decisionOptionsErrors(mixed $configured, array $workIds): array
    {
        if (!\is_array($configured)) {
            return ['escalation_policy.decision_options must be an object'];
        }
        $errors = [];
        foreach ($configured as $reason => $options) {
            if (!\is_string($reason) || $reason === '') {
                $errors[] = 'decision_options keys must be non-empty strings';
                continue;
            }
            if (!\is_array($options) || $options === [] || !array_is_list($options)) {
                $errors[] = 'decision_options.' . $reason . ' must be a non-empty list';
                continue;
            }
            $seen = [];
            foreach ($options as $index => $option) {
                $label = 'decision_options.' . $reason . '[' . $index . ']';
                if (!\is_array($option)) {
                    $errors[] = $label . ' must be an object';
                    continue;
                }
                $id = $option['id'] ?? null;
                if (!\is_string($id) || $id === '') {
                    $errors[] = $label . '.id is required';
                } elseif (isset($seen[$id])) {
                    $errors[] = 'duplicate decision option id: ' . $id;
                } else {
                    $seen[$id] = true;
                }
                if (!\is_string($option['effect'] ?? null) || $option['effect'] === '') {
                    $errors[] = $label . '.effect is required';
                }
                $patch = $option['patch'] ?? null;
                if (!\is_array($patch) || $patch === [] || !array_is_list($patch)) {
                    $errors[] = $label . '.patch must be a non-empty list';
                    continue;
                }
                foreach ($patch as $changeIndex => $change) {
                    $changeLabel = $label . '.patch[' . $changeIndex . ']';
                    if (!\is_array($change) || !\is_string($change['op'] ?? null) || $change['op'] === '') {
                        $errors[] = $changeLabel . '.op is required';
                        continue;
                    }
                    if (isset($change['scope'])) {
                        if (!\is_array($change['scope'])) {
                            $errors[] = $changeLabel . '.scope must be a list';
                        } else {
                            foreach ($change['scope'] as $workId) {
                                if (!\is_string($workId) || !isset($workIds[$workId])) {
                                    $errors[] = $changeLabel . '.scope references unknown work: ' . (string) $workId;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $rights
     * @param array<string, bool> $workIds
     * @param array<string, mixed> $authority
     * @return list<string>
     */
    private static function decisionRightsErrors(array $rights, array $workIds, array $authority): array
    {
        $errors = [];

        $signed = $rights['signed_contracts'] ?? [];
        if (!\is_array($signed)) {
            $errors[] = 'decision_rights.signed_contracts must be a list';
            $signed = [];
        }
        $seenContracts = [];
        foreach ($signed as $index => $contract) {
            $label = 'decision_rights.signed_contracts[' . $index . ']';
            if (!\is_array($contract)) {
                $errors[] = $label . ' must be an object';
                continue;
            }
            $contractId = $contract['contract_id'] ?? null;
            if (!\is_string($contractId) || $contractId === '') {
                $errors[] = $label . '.contract_id is required';
            } else {
                if (isset($seenContracts[$contractId])) {
                    $errors[] = 'duplicate signed contract id: ' . $contractId;
                }
                $seenContracts[$contractId] = true;
            }
            foreach (['signer', 'version'] as $key) {
                if (!\is_string($contract[$key] ?? null) || $contract[$key] === '') {
                    $errors[] = $label . '.' . $key . ' is required';
                }
            }
            if (isset($contract['status']) && !\in_array($contract['status'], ['ACTIVE', 'REVOKED', 'SUPERSEDED'], true)) {
                $errors[] = $label . '.status must be ACTIVE, REVOKED or SUPERSEDED';
            }
            $errors = array_merge($errors, self::scopeErrors($label . '.scope', $contract['scope'] ?? null, $workIds));
            if (!self::signerTrusted($authority, (string) ($contract['signer'] ?? ''))) {
                $errors[] = $label . '.signer is not a trusted authority';
            }
            if (isset($contract['allowed_keys']) && (!\is_array($contract['allowed_keys']) || array_filter($contract['allowed_keys'], static fn (mixed $item): bool => !\is_string($item) || $item === '') !== [])) {
                $errors[] = $label . '.allowed_keys must be a list of non-empty strings';
            }
        }

        $delegations = $rights['delegations'] ?? [];
        if (!\is_array($delegations)) {
            $errors[] = 'decision_rights.delegations must be a list';
            $delegations = [];
        }
        $seenDelegations = [];
        foreach ($delegations as $index => $delegation) {
            $label = 'decision_rights.delegations[' . $index . ']';
            if (!\is_array($delegation)) {
                $errors[] = $label . ' must be an object';
                continue;
            }
            $delegationId = $delegation['delegation_id'] ?? null;
            if (!\is_string($delegationId) || $delegationId === '') {
                $errors[] = $label . '.delegation_id is required';
            } else {
                if (isset($seenDelegations[$delegationId])) {
                    $errors[] = 'duplicate delegation id: ' . $delegationId;
                }
                $seenDelegations[$delegationId] = true;
            }
            if (!\is_string($delegation['delegate'] ?? null) || $delegation['delegate'] === '') {
                $errors[] = $label . '.delegate is required';
            }
            if (isset($delegation['status']) && !\in_array($delegation['status'], ['ACTIVE', 'REVOKED', 'SUPERSEDED'], true)) {
                $errors[] = $label . '.status must be ACTIVE, REVOKED or SUPERSEDED';
            }
            $errors = array_merge($errors, self::scopeErrors($label . '.scope', $delegation['scope'] ?? null, $workIds));
            $allowedKeys = $delegation['allowed_keys'] ?? null;
            if (!\is_array($allowedKeys) || $allowedKeys === [] || array_filter($allowedKeys, static fn (mixed $item): bool => !\is_string($item) || $item === '') !== []) {
                $errors[] = $label . '.allowed_keys must be a non-empty list of strings';
            }
            $range = $delegation['range'] ?? null;
            if (!\is_array($range) || !\is_numeric($range['min'] ?? null) || !\is_numeric($range['max'] ?? null) || (float) $range['min'] > (float) $range['max']) {
                $errors[] = $label . '.range must have numeric min <= max';
            }
        }

        return $errors;
    }

    /**
     * @param list<string>|mixed $scope
     * @param array<string, bool> $workIds
     * @return list<string>
     */
    private static function scopeErrors(string $label, mixed $scope, array $workIds): array
    {
        if (!\is_array($scope) || $scope === []) {
            return [$label . ' must be a non-empty list'];
        }
        $errors = [];
        foreach ($scope as $workId) {
            if (!\is_string($workId) || !isset($workIds[$workId])) {
                $errors[] = $label . ' references unknown work: ' . (string) $workId;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $authority
     */
    private static function signerTrusted(array $authority, string $signer): bool
    {
        if ($signer === '') {
            return false;
        }
        if ($signer === (string) ($authority['human_authorizer'] ?? '')) {
            return true;
        }
        $trusted = $authority['trusted_signers'] ?? [];

        return \is_array($trusted) && \in_array($signer, array_map('strval', $trusted), true);
    }

    /**
     * @param array<string, list<string>> $dependencies
     */
    private static function hasDependencyCycle(array $dependencies): bool
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited, $dependencies): bool {
            if (isset($visiting[$id])) {
                return true;
            }
            if (isset($visited[$id])) {
                return false;
            }
            $visiting[$id] = true;
            foreach ($dependencies[$id] ?? [] as $dependency) {
                if ($visit((string) $dependency)) {
                    return true;
                }
            }
            unset($visiting[$id]);
            $visited[$id] = true;

            return false;
        };
        foreach (array_keys($dependencies) as $id) {
            if ($visit((string) $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    public static function eventErrors(array $event): array
    {
        $errors = [];
        if (!\is_string($event['event_id'] ?? null) || $event['event_id'] === '') {
            $errors[] = 'event_id is required';
        }
        if (!\is_int($event['seq'] ?? null) || $event['seq'] < 1) {
            $errors[] = 'seq must be an integer >= 1';
        }
        if (!\is_string($event['type'] ?? null) || $event['type'] === '') {
            $errors[] = 'type is required';
        }
        $scope = $event['scope'] ?? [];
        if (!\is_array($scope)) {
            $errors[] = 'scope must be a list';
        }

        return $errors;
    }
}
